#!/usr/bin/env php
<?php
/**
 * ForeignGSM GoIP SMS relay: UDP 44444 (GoIP SMS Server) -> HTTP POST send.html on target gateway.
 * PHP 5.6+ CLI. Config: /etc/goip-relay/config.json or --config=path
 */

set_time_limit(0);
ob_implicit_flush(true);

spl_autoload_register(function ($class) {
    $class = str_replace('\\', '/', $class);
    $class = str_replace('GoIP/', '', $class);
    $path = __DIR__ . '/lib/GoIP/' . $class . '.php';
    if (is_readable($path)) {
        require $path;
    }
});

$opts = getopt('', array('config:'));
$configPath = isset($opts['config']) ? $opts['config'] : '/etc/goip-relay/config.json';

if (!is_readable($configPath)) {
    fwrite(STDERR, "goip-relay: cannot read config: {$configPath}\n");
    exit(1);
}

$raw = file_get_contents($configPath);
$config = json_decode($raw, true);
if (!is_array($config)) {
    fwrite(STDERR, "goip-relay: invalid JSON in {$configPath}\n");
    exit(1);
}

$required = array('listen_port', 'gateways', 'routes');
foreach ($required as $k) {
    if (!isset($config[$k])) {
        fwrite(STDERR, "goip-relay: missing config key: {$k}\n");
        exit(1);
    }
}

$listenHost = isset($config['listen_host']) ? $config['listen_host'] : '0.0.0.0';
$listenPort = (int) $config['listen_port'];
$logFile = isset($config['log_file']) ? $config['log_file'] : '/var/log/goip-relay.log';
$smsMaxLen = isset($config['sms_max_length']) ? (int) $config['sms_max_length'] : 160;
$debug = !empty($config['debug']);
$defaultCallingCode = isset($config['default_calling_code']) ? $config['default_calling_code'] : null;

/**
 * @param string $logFile
 * @param string $line
 */
function goip_relay_log($logFile, $line)
{
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[{$ts}] {$line}\n", FILE_APPEND | LOCK_EX);
}

/**
 * @param string $buf
 * @param int $max
 * @return string
 */
function goip_debug_preview($buf, $max = 240)
{
    $s = preg_replace('/[\x00-\x1f]/', ' ', $buf);
    if (strlen($s) > $max) {
        return substr($s, 0, $max) . '...';
    }
    return $s;
}

/**
 * @param array $config
 * @param string $clientId
 * @return string|null gateway key
 */
function goip_find_gateway_key($config, $clientId)
{
    foreach ($config['gateways'] as $key => $gw) {
        if (!empty($gw['sms_client_id']) && (string) $gw['sms_client_id'] === (string) $clientId) {
            return $key;
        }
    }
    return null;
}

/**
 * @param array $data parsed UDP packet
 * @return int line/slot (1-based)
 */
function goip_extract_slot($data)
{
    foreach (array('port', 'line', 'slot', 'cid') as $k) {
        if (isset($data[$k]) && $data[$k] !== '') {
            return max(1, (int) $data[$k]);
        }
    }
    return 1;
}

/**
 * @param array $config
 * @param string $gwKey
 * @param int $slot
 * @return array|null route
 */
function goip_find_route($config, $gwKey, $slot)
{
    foreach ($config['routes'] as $route) {
        if (!isset($route['match_gw']) || $route['match_gw'] !== $gwKey) {
            continue;
        }
        if (isset($route['match_slot'])) {
            if ((int) $route['match_slot'] !== (int) $slot) {
                continue;
            }
        }
        return $route;
    }
    return null;
}

/**
 * @param array $gw outbound gateway block
 * @param int $line
 * @param string $destination E.164 (+…), passed to send.html as n
 * @param string $body
 * @return array http_code, curl_error, response_preview
 */
function goip_send_sms_http($gw, $line, $destination, $body)
{
    $base = rtrim($gw['http_base'], '/');
    $params = array(
        'u' => isset($gw['http_user']) ? $gw['http_user'] : '',
        'p' => isset($gw['http_pass']) ? $gw['http_pass'] : '',
        'l' => (int) $line,
        'n' => $destination,
        'm' => $body,
    );
    // GoIP GS-* firmware: GET with m in the query string is truncated after the first ASCII space in m (CGI sscanf-style).
    // POST application/x-www-form-urlencoded to send.html keeps m intact (verified against SMS OutBox). Encoding: PHP_QUERY_RFC1738.
    $postBody = http_build_query($params, '', '&', PHP_QUERY_RFC1738);
    $url = $base . '/send.html';

    if (!function_exists('curl_init')) {
        return array(0, 'curl not available', '');
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $preview = is_string($resp) ? substr(preg_replace('/\s+/', ' ', $resp), 0, 120) : '';
    return array($code, $err, $preview);
}

/**
 * @param string $template
 * @param array $vars
 * @return string
 */
function goip_render_body($template, $vars)
{
    $out = $template;
    foreach ($vars as $k => $v) {
        $out = str_replace('{' . $k . '}', $v, $out);
    }
    return $out;
}

/**
 * @param string $text
 * @param int $maxLen
 * @return string
 */
function goip_truncate_sms($text, $maxLen)
{
    $cut = '[cut]';
    if (strlen($text) <= $maxLen) {
        return $text;
    }
    $keep = $maxLen - strlen($cut);
    if ($keep < 1) {
        return substr($text, 0, $maxLen);
    }
    return substr($text, 0, $keep) . $cut;
}

/**
 * Normalize recipient MSISDN to E.164 (+country + national) for GoIP send.html parameter n.
 *
 * @param string $raw From route destination (any common typing).
 * @param string|null $defaultCcDigits Optional country calling code without + (e.g. "1", "49", "7").
 *        Used when the number is national (6–12 digits after optional leading 0) and no other rule matched.
 * @return string
 */
function goip_normalize_destination_e164($raw, $defaultCcDigits = null)
{
    $s = trim((string) $raw);
    if ($s === '') {
        return $s;
    }
    $s = preg_replace('/[\s\-\.\(\)]/', '', $s);
    if (preg_match('/^\+([0-9]{8,15})$/', $s, $m)) {
        return '+' . $m[1];
    }
    if (preg_match('/^00([0-9]{8,14})$/', $s, $m)) {
        return '+' . $m[1];
    }
    $d = preg_replace('/[^0-9]/', '', $s);
    if ($d === '') {
        return $raw;
    }
    if (strlen($d) === 11 && $d[0] === '8') {
        return '+7' . substr($d, 1);
    }
    if (strlen($d) === 11 && $d[0] === '7') {
        return '+' . $d;
    }
    if (strlen($d) === 10 && $d[0] === '9') {
        return '+7' . $d;
    }
    $cc = null;
    if ($defaultCcDigits !== null && $defaultCcDigits !== '') {
        $cc = preg_replace('/[^0-9]/', '', (string) $defaultCcDigits);
        if ($cc === '') {
            $cc = null;
        }
    }
    if ($cc !== null) {
        $national = $d;
        if (isset($national[0]) && $national[0] === '0') {
            $national = substr($national, 1);
        }
        if (strlen($national) >= 6 && strlen($national) <= 12 && strlen($d) <= 12) {
            return '+' . $cc . $national;
        }
    }
    if (strlen($d) >= 11 && strlen($d) <= 15) {
        return '+' . $d;
    }
    if (strlen($d) >= 8 && strlen($d) <= 10) {
        return '+' . $d;
    }
    return '+' . $d;
}

/**
 * @param array $gw
 * @param array $data
 * @return bool
 */
function goip_validate_password($gw, $data)
{
    $expected = isset($gw['sms_password']) ? (string) $gw['sms_password'] : '';
    if ($expected === '') {
        return true;
    }
    $got = '';
    if (isset($data['password'])) {
        $got = (string) $data['password'];
    } elseif (isset($data['pass'])) {
        $got = (string) $data['pass'];
    }
    return $got === $expected;
}

$server = new GoIP\Server($listenHost, $listenPort);
$server->setReadTimeout(isset($config['read_timeout']) ? (int) $config['read_timeout'] : 1);

$server->on('bind', function ($server) use ($logFile, $listenHost, $listenPort) {
    goip_relay_log($logFile, "bind {$listenHost}:{$listenPort}");
});

$server->on('ack', function ($server) use ($logFile, $debug) {
    if ($debug) {
        $o = $server->getOrigin();
        $h = isset($o['host']) ? $o['host'] : '';
        $p = isset($o['port']) ? $o['port'] : '';
        goip_relay_log($logFile, 'keepalive_ack from=' . $h . ':' . $p);
    } else {
        goip_relay_log($logFile, 'keepalive_ack');
    }
});

$server->on('ack-fail', function ($server) use ($logFile, $debug) {
    if ($debug) {
        $o = $server->getOrigin();
        $h = isset($o['host']) ? $o['host'] : '';
        $p = isset($o['port']) ? $o['port'] : '';
        goip_relay_log($logFile, 'keepalive_ack_fail to=' . $h . ':' . $p);
    } else {
        goip_relay_log($logFile, 'keepalive_ack_fail');
    }
});

$server->on('message', function ($server, $buffer) use ($config, $logFile, $smsMaxLen, $debug, $defaultCallingCode) {
    if ($debug) {
        $o = $server->getOrigin();
        $oh = isset($o['host']) ? $o['host'] : '';
        $op = isset($o['port']) ? $o['port'] : '';
        goip_relay_log($logFile, 'receive_udp from=' . $oh . ':' . $op . ' raw=' . goip_debug_preview($buffer));
    }

    $data = GoIP\Util::enrichReceiveFields($buffer, GoIP\Util::parseArray($buffer));
    $clientId = isset($data['id']) ? $data['id'] : '';

    $gwKey = goip_find_gateway_key($config, $clientId);
    if ($gwKey === null) {
        goip_relay_log($logFile, 'receive_unknown_client id=' . $clientId);
        return;
    }

    $gwIn = $config['gateways'][$gwKey];
    if (!goip_validate_password($gwIn, $data)) {
        goip_relay_log($logFile, 'receive_bad_password gw=' . $gwKey);
        return;
    }

    $slot = goip_extract_slot($data);
    $route = goip_find_route($config, $gwKey, $slot);
    if ($route === null) {
        goip_relay_log($logFile, 'receive_no_route gw=' . $gwKey . ' slot=' . $slot);
        return;
    }

    $outKey = $route['out_gw'];
    if (!isset($config['gateways'][$outKey])) {
        goip_relay_log($logFile, 'receive_bad_out_gw ' . $outKey);
        return;
    }
    $gwOut = $config['gateways'][$outKey];

    $srcnum = isset($data['srcnum']) ? $data['srcnum'] : '';
    $msg = isset($data['msg']) ? $data['msg'] : '';
    $template = isset($route['body_template']) ? $route['body_template'] : "From:{srcnum} {msg}";

    $body = goip_render_body($template, array(
        'srcnum' => $srcnum,
        'msg' => $msg,
    ));
    $body = goip_truncate_sms($body, $smsMaxLen);

    $destRaw = isset($route['destination']) ? $route['destination'] : '';
    $dest = goip_normalize_destination_e164($destRaw, $defaultCallingCode);
    $outSlot = isset($route['out_slot']) ? (int) $route['out_slot'] : 1;

    if ($debug) {
        if ($destRaw !== '' && $dest !== $destRaw) {
            goip_relay_log($logFile, 'forward_dst_norm raw=' . $destRaw . ' e164=' . $dest);
        }
        $bodyDbg = goip_debug_preview($body, 200);
        goip_relay_log(
            $logFile,
            'forward_debug gw=' . $gwKey . ' slot=' . $slot . ' -> ' . $outKey . ' L' . $outSlot
            . ' dst=' . $dest . ' body_preview=' . $bodyDbg
        );
    }

    list($httpCode, $curlErr, $preview) = goip_send_sms_http($gwOut, $outSlot, $dest, $body);

    goip_relay_log(
        $logFile,
        'forward gw=' . $gwKey . ' slot=' . $slot . ' -> ' . $outKey . ' L' . $outSlot
        . ' dst=' . $dest . ' srcnum=' . $srcnum
        . ' http=' . $httpCode . ' curl_err=' . ($curlErr ? $curlErr : '-')
        . ' resp=' . $preview
    );
});

$server->loop();
