<?php


namespace Lan\Speed;

use Evenement\EventEmitter;
use Lan\Speed\Impl\MessageInterface;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;

abstract class Process extends EventEmitter
{
    /** @var int 进程终止状态 */
    const STATE_SHUTDOWN = 0;

    /** @var int 进程执行状态 */
    const STATE_RUNNING = 1;

    /** @var int 进程空闲状态， */
    const STATE_FREE = 2;

    /** @var int 清除缓存状态信息 */
    const STATE_FLUSH_CACHE = 3;

    /** @var LoopInterface $eventLoop 事件处理器 */
    protected $eventLoop;

    /**
     * @var string 进程名称
     */
    protected $name;

    /**
     * @var string|integer 进程pid
     */
    protected $pid;


    private $signalHandlers = array();

    /**
     * 父进程和子进程都需要实现此方法，用于父进程与子进程的通信
     * @param MessageInterface $message
     * @return mixed
     */
    abstract public function handleMessage(MessageInterface $message);

    public function __construct() {
        $this->eventLoop = Factory::create();
    }

    /**
     * 将信号添加到事件处理器当中
     * @param $signal
     * @param callable $handler
     * @return $this
     */
    public function addSignal($signal, callable $handler) {
        $this->eventLoop->addSignal($signal, $handler);
        $this->signalHandlers[$signal][] = $handler;
        return $this;
    }

    /**
     * 移除所有信号
     */
    public function removeAllSignal() {
        foreach ($this->signalHandlers as $signal => $handlers) {
            foreach ($handlers as $handle) {
                $this->eventLoop->removeSignal($signal, $handle);
            }
        }

        $this->signalHandlers = array();
    }


    /**
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param mixed $name
     */
    public function setName($name)
    {
        $this->name = $name;
        cli_set_process_title($this->name);
        return $this;
    }

    /**
     * @return mixed
     */
    public function getPid()
    {
        return $this->pid;
    }

    /**
     * @param mixed $pid
     */
    public function setPid($pid)
    {
        $this->pid = $pid;
    }

    public function getEventLoop() {
        return $this->eventLoop;
    }

    public function getSignalHandler() {
        return $this->signalHandlers;
    }
}