<?php
/**
 * GoIP Client/Server Package based on GoIP SMS Gateway Interface.
 * (c) 2014-2016 Openovate Labs — MIT.
 */

namespace GoIP;

class Util extends Base
{
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
        $data = self::parseArray($buffer);
        if (isset($data['RECEIVE']) && isset($data['msg'])) {
            return $data;
        }
        return array();
    }
}
