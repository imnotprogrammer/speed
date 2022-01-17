<?php


namespace Lan\Speed;


class WorkerFactory
{
    /**
     * @var array 信号处理器
     */
    protected $signalHandlers = array();

    /** @var array 事件 */
    protected $events = array();

    /** @var bool 是否覆盖自己包含的SIGINT,SIGTERM 信号 */
    protected $coverKillSignal = false;

    private $workerClass = null;

    /**
     * 创建子进程
     * @param $socket
     * @return Worker
     */
    public function makeWorker($socket) {
        if ($this->workerClass) {
            $class = $this->workerClass;
            /** @var Process $worker */
            $worker = new $class($socket);
        } else {
            $worker = new Worker($socket);
        }

        if ($this->signalHandlers) {
            foreach ($this->signalHandlers as $signal => $handlers) {
                if (in_array($signal, [SIGINT, SIGTERM]) && !$this->coverKillSignal) {
                    continue;
                }

                foreach ($handlers as $handler) {
                    $wrapped = function ($callback) use ($worker) {
                        return function ($signal) use ($callback, $worker) {
                            $callback($signal, $worker);
                        };
                    };
                    $worker->addSignal($signal, $wrapped($handler));
                }
            }
        }

        if ($this->events) {
            foreach ($this->events as $event => $handlers) {
                foreach ($handlers as $handler) {
                    $worker->on($event, $handler);
                }
            }
        }

        return $worker;
    }

    /**
     * 子进程添加事件
     * @param $event
     * @param callable $handler
     */
    public function registerEvent($event, callable $handler) {
        if (isset($this->events[$event])) {
            if (in_array($handler, $this->events[$event])) {
                return $this;
            }
        } else {
            $this->events[$event] = array();
        }
        $this->events[$event][] = $handler;
        return $this;
    }

    /**
     * 子进程添加信号处理器
     * @param $signal
     * @param callable $handler
     */
    public function addSignal($signal, callable $handler) {
        if (isset($this->signalHandlers[$signal])) {
            if (in_array($handler, $this->signalHandlers[$signal])) {
                return $this;
            }
        } else {
            $this->signalHandlers[$signal] = array();
        }
        $this->signalHandlers[$signal][] = $handler;
        return $this;
    }

    /**
     * @param $status
     * @return $this
     */
    public function setCoverKillSignal($status) {
        $this->coverKillSignal = $status;
        return $this;
    }

    public function setWorkerClass($className) {
        $this->workerClass = $className;
        return $this;
    }
}