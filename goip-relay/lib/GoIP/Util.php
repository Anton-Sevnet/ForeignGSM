<?php
/**
 * GoIP Client/Server Package based on GoIP SMS Gateway Interface.
 * (c) 2014-2016 Openovate Labs — MIT.
 */

namespace GoIP;

class Util extends Base
{
    /**
     * Enrich parsed UDP fields for RECEIVE (incoming SMS).
     * Naive explode(';') truncates msg when the body contains ';' or firmware sends extra tail fields.
     * Same idea as jamhed/goip goip-sms.pl: msg:(.+)$ over the full buffer.
     *
     * @param string $buffer
     * @param array $parsed
     * @return array
     */
    public static function enrichReceiveFields($buffer, array $parsed)
    {
        if (!isset($parsed['RECEIVE'])) {
            return $parsed;
        }
        $buf = trim($buffer);
        if ($buf !== '' && preg_match('/msg:(.*)$/is', $buf, $m)) {
            $parsed['msg'] = rtrim($m[1], ";\r\n");
        }
        if (!isset($parsed['msg'])) {
            $parsed['msg'] = '';
        }
        if ($parsed['msg'] === '') {
            foreach (array('MSG', 'message', 'sms', 'smsmsg', 'content', 'body', 'text') as $alt) {
                if (!empty($parsed[$alt])) {
                    $parsed['msg'] = $parsed[$alt];
                    break;
                }
            }
        }

        return $parsed;
    }

    public static function parseArray($buffer)
    {
        $data = explode(';', $buffer);
        $parsed = array();

        foreach ($data as $value) {
            $parts = explode(':', $value);
            $key = array_shift($parts);
            $val = implode(':', $parts);
            if (strlen($key) == 0) {
                continue;
            }
            $parsed[$key] = $val;
        }

        return $parsed;
    }

    public static function parseString($buffer)
    {
        $data = self::parseArray($buffer);
        $parsed = '';
        foreach ($data as $key => $value) {
            $parsed .= $key . ' : ' . $value . PHP_EOL;
        }
        return $parsed;
    }

    public static function getMessage($buffer)
    {
        $data = self::enrichReceiveFields($buffer, self::parseArray($buffer));
        if (isset($data['RECEIVE']) && isset($data['msg'])) {
            return $data;
        }
        return array();
    }
}
