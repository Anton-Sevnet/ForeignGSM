<?php
/**
 * GoIP Client/Server Package based on GoIP SMS Gateway Interface.
 * (c) 2014-2016 Openovate Labs — MIT.
 */

namespace GoIP;

class Request extends Base
{
    const MAX_LENGTH = 3000;

    protected $socket = null;
    protected $host = null;
    protected $port = null;
    protected $password = null;
    protected $buffer = array();
    protected $sendId = null;
    protected $debug = false;

    public function __construct($socket, $host, $port, $password = null)
    {
        $this->socket = $socket;
        $this->host = $host;
        $this->port = $port;
        $this->password = $password;
    }

    public function ackMessage($id, $status = 200)
    {
        $message = $this->message()->getConstant('ACK_MESSAGE', $id, $status);
        return $this->send($message);
    }

    public function receivedAck($id, $status)
    {
        $message = $this->message()->getConstant('RECEIVE_SMS_ACK', $id, $status);
        return $this->send($message);
    }

    public function send($message)
    {
        if ($this->debug) {
            $this->debugMessage('Send', $message);
        }
        return socket_sendto($this->socket, $message, strlen($message), 0, $this->host, $this->port);
    }

    public function setDebug($debug = false)
    {
        $this->debug = $debug;
        return $this;
    }

    public function debugMessage($type, $message)
    {
        print $type . ': ' . $message . PHP_EOL;
        return $this;
    }

    public function message()
    {
        return new Message();
    }
}
