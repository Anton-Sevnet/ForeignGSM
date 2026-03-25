<?php
/**
 * GoIP UDP server loop.
 * (c) 2014-2016 Openovate Labs — MIT.
 */

namespace GoIP;

class Server extends Event
{
    protected $socket = null;
    protected $host = null;
    protected $port = null;
    protected $timeout = 1;
    protected $end = false;
    protected $origin = array('host' => null, 'port' => null);

    public function __construct($host, $port)
    {
        $this->host = $host;
        $this->port = $port;

        $this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($this->socket < 0) {
            exit(1);
        }

        $bind = socket_bind($this->socket, $this->host, $this->port);
        if ($bind < 0 || socket_last_error() > 0) {
            exit(1);
        }

        socket_set_nonblock($this->socket);
    }

    public function setReadTimeout($timeout = 1)
    {
        $this->timeout = $timeout;
        return $this;
    }

    public function getHost()
    {
        return $this->host;
    }

    public function getPort()
    {
        return $this->port;
    }

    public function getOrigin($type = null)
    {
        if (!is_null($type)) {
            return isset($this->origin[$type]) ? $this->origin[$type] : null;
        }
        return $this->origin;
    }

    public function loop()
    {
        $this->trigger('bind', $this, $this->host, $this->port);

        while (!$this->end) {
            $request = @socket_recvfrom($this->socket, $buffer, 2048, 0, $from, $port);

            $this->origin = array(
                'host' => $from,
                'port' => $port,
            );

            if (is_null($request) || !$request) {
                $this->trigger('wait', $this);
                if (!$this->end) {
                    sleep($this->timeout);
                }
                continue;
            }

            $this->trigger('data', $this, $buffer);

            $data = Util::parseArray($buffer);

            if (isset($data['req'])) {
                $acked = $this->request($from, $port)->ackMessage($data['req'], 200);
                if ($acked === false) {
                    $this->trigger('ack-fail', $this);
                } else {
                    $this->trigger('ack', $this);
                }
                if (!$this->end) {
                    sleep($this->timeout);
                }
                continue;
            }

            $message = Util::getMessage($buffer);
            if (!empty($message)) {
                $this->request($from, $port)->receivedAck($message['RECEIVE'], 'OK');
                $this->trigger('message', $this, $buffer);
                if (!$this->end) {
                    sleep($this->timeout);
                }
            }
        }

        if ($this->end) {
            exit();
        }

        return $this;
    }

    public function request($host, $port)
    {
        return new Request($this->socket, $host, $port);
    }

    public function end()
    {
        socket_close($this->socket);
        $this->end = true;
        $this->trigger('end');
        return $this;
    }
}
