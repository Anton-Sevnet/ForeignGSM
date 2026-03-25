<?php
/**
 * GoIP Client/Server Package based on GoIP SMS Gateway Interface.
 * (c) 2014-2016 Openovate Labs — MIT.
 */

namespace GoIP;

class Message
{
    const ACK_MESSAGE = 'reg:%s;status:%s;';
    const BULK_SMS_REQUEST = 'MSG %s %s %s\n';
    const AUTHENTICATION_REQUEST = 'PASSWORD %s %s\n';
    const SUBMIT_NUMBER_REQUEST = 'SEND %s %s %s';
    const END_REQUEST = 'DONE %s\n';
    const RECEIVE_SMS_ACK = 'RECEIVE %s %s\n';

    public function getConstant()
    {
        $args = func_get_args();
        $const = array_shift($args);
        $message = constant('self::' . $const);
        array_unshift($args, $message);
        return call_user_func_array('sprintf', $args);
    }
}
