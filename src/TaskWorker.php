<?php


namespace Lan\Speed;


use Lan\Speed\Impl\JobInterface;
use Lan\Speed\Impl\MessageInterface;
use React\EventLoop\Timer\Timer;

class TaskWorker extends Process
{
    const ACTION_RUN_JOB = 1;

    const ACTION_EXIT_JOB = 2;

    const EXECUTED_STATUS_NO_RUNNING = 0;

    const EXECUTED_STATUS_RUNNING = 1;

    const EXECUTED_STATUS_FAILED = 2;

    /** @var Stream $stream */
    private $stream;

    /** @var Timer null 超时检测定时器 */
    private $timeoutTimer = null;

    /** @var Timer null 空闲检测定时器 */
    private $freeCheckTimer = null;

    /** @var int 执行状态 */
    private $executedStatus = self::EXECUTED_STATUS_NO_RUNNING;

   /** @var null  */
    private $executedError = null;

    private $isEnd = false;


    public function __construct($socketStream)
    {
        parent::__construct();
        $this->stream = new Stream($socketStream, $this->eventLoop);
        $this->stream->on('data', [$this->stream, 'baseRead']);
        $this->stream->on('message', [$this, 'onReceive']);
        $this->stream->on('error', function (\Exception $error, Stream $stream) {
            var_dump($this->isEnd);
            if ($this->isEnd) {
                return;
            }
            $this->emit('error', [$error, $this]);
        });
        $this->stream->on('close', function () {
            $this->stop();
        });

        pcntl_signal(SIGINT, SIG_IGN);
    }

    public function run()
    {
        if ($this->stream->isReadable() || $this->stream->isWritable()) {
            $this->state = self::STATE_RUNNING;
        }

        $this->addFreeCheckTimer();
        $this->emit('start', [$this]);

        while ($this->state == self::STATE_RUNNING) {
            try {
                $this->eventLoop->run();
            } catch (\Exception $ex) {

            }

            if (!$this->stream->isWritable() || !$this->stream->isReadable()) {
                if (!$this->isEnd) {
                    break;
                }
            }
        }

        $this->emit('end', [$this]);
        exit(0);

    }
    public function stop() {
        $this->removeFreeCheckTimer();
        $this->removeTimeoutTimer();
        $this->eventLoop->stop();
        $this->state = self::STATE_SHUTDOWN;
    }

    /**
     * 移除定时器
     */
    public function removeTimeoutTimer() {
        if ($this->timeoutTimer) {
            $this->eventLoop->cancelTimer($this->timeoutTimer);
            $this->timeoutTimer = null;
        }
    }

    public function setEnd($status) {
        if (in_array($this->executedStatus, [self::EXECUTED_STATUS_FAILED, self::EXECUTED_STATUS_NO_RUNNING])) {
            $this->stop();
            return;
        }

        $this->isEnd = $status;

    }
    /**
     * 移除空闲检测定时器
     */
    public function removeFreeCheckTimer() {
        if ($this->freeCheckTimer) {
            $this->eventLoop->cancelTimer($this->freeCheckTimer);
            $this->freeCheckTimer = null;
        }
    }

    /**
     * 读取stream 消息
     * @param MessageInterface $message
     * @return mixed|void
     */
    public function handleMessage(MessageInterface $message)
    {
        $this->removeFreeCheckTimer();

        switch ($message->getAction()) {
            case MessageAction::MESSAGE_DISPATCH_JOB:
                $job = $message->getBody();
                $job = isset($job['job']) ? $job['job'] : null;
                if (!$job instanceof JobInterface) {
                    return;
                }

                $this->executedStatus = self::EXECUTED_STATUS_RUNNING;

                try {
                    $this->timeoutTimer = $this->eventLoop->addTimer($job->getTimeout() + 1, function () use ($job) {
                        // 程序是否处于运行状态且超时需要立即退出
                        if ($this->executedStatus == self::EXECUTED_STATUS_RUNNING &&
                            !$job->alwaysExecuteWhenTimeout()
                        ) {
                            $this->stop();
                        }

                        $this->removeTimeoutTimer();
                    });

                    $job->run();
                    $this->removeTimeoutTimer();
                    $this->executedStatus = self::EXECUTED_STATUS_NO_RUNNING;

                } catch (\Exception $ex) {
                    $this->executedError = $ex;
                    $this->executedStatus = self::EXECUTED_STATUS_FAILED;
                }

                if ($this->isEnd) {
                    $this->stop();
                    return;
                }

                $this->jobAck();
                break;
            case MessageAction::MESSAGE_LAST:
                $this->setEnd(true);
                break;
            default:
                break;
        }

        $this->addFreeCheckTimer();
    }

    public function addFreeCheckTimer() {
        if ($this->freeCheckTimer) {
            return;
        }

        $this->freeCheckTimer = $this->eventLoop->addTimer(120, function () {
            $this->stop();
        });
    }

    public function jobAck() {
        return $this->IPC(new Message(MessageAction::MESSAGE_FINISHED_JOB, [
            'isSuccess' => !($this->executedStatus == self::EXECUTED_STATUS_FAILED),
            'error' => $this->executedError
        ]), $this->stream);
    }
}