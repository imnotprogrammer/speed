<?php


namespace Lan\Speed;


use Lan\Speed\Exception\MessageFormatExcetion;
use Lan\Speed\Impl\MessageInterface;
use React\EventLoop\Timer\Timer;

class Worker extends Process
{
    const STATE_READING_EXIT = 3;

    /** @var Stream $communication */
    private $communication;

    /**
     * @var int 进程当前状态
     */
    private $state = self::STATE_SHUTDOWN;

    /**
     * @var int 进程最大空闲时间，超过此值，会自动退出
     */
    private $maxFreeTime = 0;

    /**
     * 子进程阻塞时间
     * @var int
     */
    private $blockPeriod = 1;

    /**
     * 监控进程是否达到最大空闲时间
     * @var null
     */
    private $monitorTimer = null;

    /**
     * @var array 统计信息
     */
    private $statistics = array();

    /**
     * @var Timer $processTimer 定时器
     */
    private $processTimer = null;

    /**
     * @return int
     */
    public function getMaxFreeTime()
    {
        return $this->maxFreeTime;
    }

    /**
     * @param int $maxFreeTime
     */
    public function setMaxFreeTime($maxFreeTime)
    {
        $this->maxFreeTime = $maxFreeTime;
    }

    public function __construct($stream)
    {
        parent::__construct();
        $this->setPid(posix_getpid());

        $this->communication = new Stream($stream, $this->getEventLoop());
        $this->communication->on('data', [$this->communication, 'baseRead']);
        $this->communication->on('message', [$this, 'receiveMessage']);
        $this->communication->on('error', function (\Exception $error, Stream $stream) {
            $this->emit('error', [$error, $this]);
        });
    }

    /**
     * 处理从父进程接收到的消息
     * @param MessageInterface $message
     * @return mixed|void
     */
    public function handleMessage(MessageInterface $message)
    {
        try {
            // 有消息处理，则需要重新更新定时器时间，避免因空闲而发送退出进程消息
            $this->cancelMonitorTimer();
            switch ($message->getAction()) {
                // 处理主进程派发的任务消息，的处理逻辑交给给worker监听的message事件处理
                case MessageAction::MESSAGE_CONSUME:
                    $this->emit('message', [$this, $message]);
                    $this->sendMessage(new \Lan\Speed\Message(MessageAction::MESSAGE_FINISHED, [
                        'pid' => $this->getPid(),
                    ]));
                    // 不在处理消息
                    if ($this->state == self::STATE_READING_EXIT) {
                        $this->sendReadyExitMessage();
                    }
                    break;
                // 主进程已经收到了子进程的消息，并且已经准备好，这里会发送回执消息
                case MessageAction::MESSAGE_LAST:
                    $this->sendMessage(new \Lan\Speed\Message(MessageAction::MESSAGE_QUIT_ME, [
                        'pid' => $this->getPid()
                    ]));
                    break;
                case MessageAction::MESSAGE_YOU_EXIT:
                    $this->stop();
                    return;
                    break;
                case MessageAction::MESSAGE_CUSTOM:
                default:
            }
            // 重新更新定时器
            $this->updateMonitorTimer();
        } catch (\Exception $ex) {
            $this->emit('error', [$ex]);
        }



    }

    /**
     * 这个定时器，用于监控进程最大空闲时间，以方便退出
     */
    public function updateMonitorTimer() {
        // 不允许进程空闲退出
        if ($this->maxFreeTime <= 0 || $this->monitorTimer) {
            return;
        }

        $this->monitorTimer = $this->getEventLoop()->addTimer($this->maxFreeTime, function ($timer) {
            // 进程准备退出
            try {
                $this->sendReadyExitMessage();
            } catch (\Exception $ex) {
                $this->emit('error', [$ex]);
            }

        });
    }

    /**
     * 取消监控定时器,
     */
    public function cancelMonitorTimer() {
        if (!$this->monitorTimer) {
            return;
        }

        $this->eventLoop->cancelTimer($this->monitorTimer);
        $this->monitorTimer = null;
    }

    /**
     * 子进程发送准备退出消息
     * 两种情况，
     * case1: 进程空闲超过最大空闲时间限制
     * case2: 进程已经达到退休(进程最大运行时间)
     * @throws \Exception
     */
    public function sendReadyExitMessage() {

        $this->sendMessage(new \Lan\Speed\Message(MessageAction::MESSAGE_READY_EXIT, [
            'pid' => $this->getPid()
        ]));
    }

    /**
     * 子进程挂起
     */
    public function run() {
        $this->state = self::STATE_RUNNING;
        $this->emit('start', [$this]);

        $this->updateMonitorTimer();

        while ($this->state != self::STATE_SHUTDOWN) {
            $this->processTimer = $this->eventLoop->addTimer($this->blockPeriod, function ($timer) {
                $this->cancelProcessTimer();
                $this->eventLoop->stop();
            });

            try {
                $this->eventLoop->run();
            } catch (\Exception $ex) {
                $this->emit('error', [$ex, $this]);
            }

            if (!$this->communication->isReadable() || !$this->communication->isWritable()) {
                $this->emit('disconnect', [$this]);
                break;
            }
        }

        //$this->communication->end();
        //$this->removeEventLoop();
        $this->emit('end', [$this]);
        exit(0);
    }

    /**
     * 进程停止
     */
    public function stop() {
        $this->state = self::STATE_SHUTDOWN;
        $this->cancelMonitorTimer();
        $this->cancelProcessTimer();
        $this->eventLoop->stop();
    }

    /**
     * 取消阻塞定时器
     */
    public function cancelProcessTimer() {
        if ($this->processTimer) {
            $this->eventLoop->cancelTimer($this->processTimer);
            $this->processTimer = null;
        }
    }

    /**
     * 向主进程发送消息
     * @param MessageInterface $message
     */
    public function sendMessage(MessageInterface $message) {
        if ($this->communication && $this->communication->isWritable()) {
            $this->communication->baseWrite(serialize($message));
        } else {
            echo sprintf('%s send exit message failed!!! '.PHP_EOL, $this->getPid());
            throw new \Exception($this->getPid() . 'socket channel closed');
        }
    }

    /**
     * 收到主进程发来的消息，然后转发给消息处理器
     * @param $content
     * @param Stream $stream
     * @throws MessageFormatExcetion
     */
    public function receiveMessage($content, Stream $stream) {
        /** @var MessageInterface $message */
        $message = unserialize($content);
        if ($message instanceof MessageInterface) {
            $this->handleMessage($message);
        } else if (is_string($message)) {
            $message = json_decode($message, true);
            if ($message) {
                $this->handleMessage($message);
            }
        } else {
            throw new MessageFormatExcetion();
        }

    }

    public function getName()
    {
        return parent::getName();
    }
}