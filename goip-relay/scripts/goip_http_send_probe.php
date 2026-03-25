#!/usr/bin/env php
<?php
/**
 * One-off: probe GoIP HTTP SMS from ATS. Usage: php goip_http_send_probe.php
 * Edit $goipBase, $user, $pass, $line, $n, $label before run.
 */
$goipBase = 'http://192.168.77.32/default/en_US';
$user = 'admin';
$pass = 'admin';
$line = 1;
$n = '+79787271759';

function one_curl($name, $url, $opts = array())
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERPWD, 'admin:admin');
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    foreach ($opts as $k => $v) {
        curl_setopt($ch, $k, $v);
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    echo "=== {$name} http={$code} err=" . ($err ? $err : '-') . " resp=" . trim(preg_replace('/\s+/', ' ', substr((string) $body, 0, 200))) . "\n";
}

$msgPost = 'From:+79781111111 H1_POST кириллица и пробелы';
$msgGet = 'From:+79781111111 H2_GET кириллица и пробелы';
$msgInfo = 'From:+79781111111 H3_SMSINFO кириллица и пробелы';

$params = array(
    'u' => $user,
    'p' => $pass,
    'l' => $line,
    'n' => $n,
    'm' => $msgPost,
);
$encBody = http_build_query($params, '', '&', PHP_QUERY_RFC1738);

// H1: POST send.html (body = form)
one_curl('H1_POST_sendhtml', rtrim($goipBase, '/') . '/send.html', array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $encBody,
    CURLOPT_HTTPHEADER => array('Content-Type: application/x-www-form-urlencoded'),
));

sleep(3);

$params['m'] = $msgGet;
$q = http_build_query($params, '', '&', PHP_QUERY_RFC1738);
one_curl('H2_GET_sendhtml', rtrim($goipBase, '/') . '/send.html?' . $q, array());

sleep(3);

// H3: sms_info.html POST (Stack Overflow / веб-форма «Send SMS»)
$rand = (string) mt_rand(100000, 999999);
$infoBody = http_build_query(array(
    'line' => (string) $line,
    'smskey' => $rand,
    'action' => 'sms',
    'telnum' => $n,
    'smscontent' => $msgInfo,
    'send' => 'send',
), '', '&', PHP_QUERY_RFC1738);
one_curl('H3_POST_smsinfo', rtrim($goipBase, '/') . '/sms_info.html', array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $infoBody,
    CURLOPT_HTTPHEADER => array('Content-Type: application/x-www-form-urlencoded'),
));

echo "done\n";
