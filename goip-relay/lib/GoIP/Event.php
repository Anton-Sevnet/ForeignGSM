<?php
/**
 * Event observer (Eden-style).
 * (c) 2014-2016 Openovate Labs — MIT.
 */

namespace GoIP;

class Event extends Base
{
    protected $observers = array();

    public function off($event = null, $callable = null)
    {
        if (is_null($event) && is_null($callable)) {
            $this->observers = array();
            return $this;
        }

        $id = $this->getId($callable);

        foreach ($this->observers as $i => $observer) {
            if (!is_null($event) && $event != $observer[0]) {
                continue;
            }
            if (!is_null($callable) && $id != $observer[1]) {
                continue;
            }
            unset($this->observers[$i]);
        }

        return $this;
    }

    public function on($event, $callable, $important = false)
    {
        $id = $this->getId($callable);
        $observer = array($event, $id, $callable);

        if ($important) {
            array_unshift($this->observers, $observer);
            return $this;
        }

        $this->observers[] = $observer;
        return $this;
    }

    public function trigger($event = null)
    {
        if (is_null($event)) {
            $trace = debug_backtrace();
            $event = $trace[1]['function'];
            if (isset($trace[1]['class']) && trim($trace[1]['class'])) {
                $event = str_replace('\\', '-', $trace[1]['class']) . '-' . $event;
            }
        }

        $args = func_get_args();
        array_shift($args);

        foreach ($this->observers as $observer) {
            if ($event == $observer[0] && call_user_func_array($observer[2], $args) === false) {
                break;
            }
        }

        return $this;
    }

    protected function getId($callable)
    {
        if (is_array($callable)) {
            if (is_object($callable[0])) {
                $callable[0] = spl_object_hash($callable[0]);
            }
            return $callable[0] . '::' . $callable[1];
        }
        if (is_string($callable)) {
            return $callable;
        }
        return false;
    }
}
