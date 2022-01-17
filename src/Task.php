<?php


namespace Lan\Speed;


use Lan\Speed\Exception\SocketCreateException;
use Lan\Speed\Exception\SocketWriteException;
use Lan\Speed\Exception\WorkerCreateException;
use Lan\Speed\Impl\JobInterface;
use Lan\Speed\Impl\MessageInterface;

class Task extends Process
{
    private $tasks;

    private $cronTimer = null;

    private $loopPeriod = 1 * 60; //s

    private $scheduleWorker = null;
    private $workers;

    const JOB_STATE_RUNNING = 1;
    const JOB_STATE_CAN_EXECUTE = 0;
    const JOB_STATE_TIMEOUT = 2;
    /**
     * @var WorkerFactory
     */
    private $workerFactory;

    private $processTimer = null;

    public function __construct(WorkerFactory $workerFactory) {
        $this->scheduleWorker = new ScheduleWorker();
        $this->workerFactory = $workerFactory;
        parent::__construct();
    }

    public function hasAvailableWorker() {
        return $this->scheduleWorker->getFreeWorker() > 0;
    }

    /**
     * @throws SocketWriteException
     */
    public function run()
    {
        $this->cronTimer = $this->eventLoop->addPeriodicTimer($this->loopPeriod, function () {
            foreach ($this->tasks as $jobId => $task) {
               $this->dispatch($jobId, $task);
            }
        });

        $this->state = self::STATE_RUNNING;

        while ($this->state == self::STATE_RUNNING) {
            try {
                $this->eventLoop->run();
            } catch (\Exception $ex) {
                $this->emit('error', array($ex));
            }
        }

        $this->clearWorkers();
    }

    /**
     * 退出所有子进程
     * @throws SocketWriteException
     */
    public function clearWorkers() {

    }

    /**
     * @param $jobId
     * @param $task
     * @return bool|void
     * @throws SocketCreateException
     * @throws SocketWriteException
     * @throws WorkerCreateException
     */
    public function dispatch($jobId, $task, $onceExecute = false) {
        /** @var JobInterface $job */
        $job = isset($task['job']) ? $task['job'] : null;
        $state = isset($task['job']) ? $task['state'] : self::JOB_STATE_CAN_EXECUTE;

        if (!$job) {
            return;
        }
        /**
         * 1）。当定时任务超时，并且相关job设置为超时后立即执行或当定时任务到了执行时间
         * 2）。相关定时任务目前状态是可执行状态时
         */
        if (($onceExecute || $job->isRunAble()) && $state == self::JOB_STATE_CAN_EXECUTE) {
            if ($this->hasAvailableWorker()) {
                $pid = $this->scheduleWorker->allocate();
            } else {
                $pid = $this->createWorker();
            }

            if ($pid) {
                $this->scheduleWorker->workerBusy($pid);
                $this->workers[$pid]['jobId'] = $jobId;  // 标识当前进程有任务执行
                $this->tasks[$jobId]['state'] = self::JOB_STATE_RUNNING;
                $this->tasks[$jobId]['lastStartTime'] = time();

                if (!$this->tasks[$jobId]['firstExecuteTime']) {
                    $this->tasks[$jobId]['firstExecuteTime'] = time();
                }

                return $this->IPC(new Message(MessageAction::MESSAGE_DISPATCH_JOB, [
                    'job' => $job
                ]), $this->workers[$pid]['stream']);

            }

        } else if ($job->isRunAble() && $state == self::JOB_STATE_RUNNING) {
            // 任务执行已经超时
            $this->tasks[$jobId]['state'] = self::JOB_STATE_TIMEOUT;
        }


    }

    /**
     * 创建子进程，创建时机为没有空闲子进程时，系统自动创建进程处理任务
     * @return int 进程id
     * @throws SocketCreateException
     * @throws WorkerCreateException
     */
    public function createWorker() {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (!$sockets) {
            throw new SocketCreateException();
        }

        $pid = pcntl_fork();
        if ($pid > 0) { // 父进程
            fclose($sockets[0]);
            unset($sockets[0]);

            $stream = new Stream($sockets[1], $this->eventLoop);
            $stream->on('data', array($stream, 'baseRead'));
            $stream->on('message', array($this, 'onReceive'));
            $stream->on('error', function ($error, Stream $stream) use ($pid) {
                $stream->close();
                throw new SocketWriteException($error->getMessage());
            });

            $stream->on('close', function ($stream) use ($pid) {
                if (!isset($this->workers[$pid])) {
                    return;
                }

                unset($this->workers[$pid]);
                $this->scheduleWorker->retireWorker($pid);
                $this->emit('workerExit', array($pid, $this));
            });

            $this->workers[$pid]['stream'] = $stream;
            $this->scheduleWorker->workerFree($pid);
        } else if ($pid == 0) {
            try {
                //$this->cancelProcessTimer();
                $this->removeAllListeners();
                $this->removeAllSignal();
                $this->eventLoop->stop();

                fclose($sockets[1]);

                unset(
                    $sockets[1], $this->eventLoop, $this->statistics, //$this->workers,
                    $this->stashMessage, $this->scheduleWorker
                );

                $worker = $this->workerFactory->makeWorker($sockets[0]);
                $worker->run();
            } catch (\Exception $ex) {
                exit(-1);
            }

        } else {
            throw new WorkerCreateException();
        }

        return $pid;
    }

    /**
     * 停止
     */
    public function stop() {
        if ($this->cronTimer) {
            $this->eventLoop->cancelTimer($this->cronTimer);
            $this->cronTimer = null;
        }

        $this->state = self::STATE_SHUTDOWN;
        $this->eventLoop->stop();
    }

    /**
     * 消息处理
     * @param MessageInterface $message
     * @return mixed|void
     * @throws SocketCreateException
     * @throws SocketWriteException
     * @throws WorkerCreateException
     */
    public function handleMessage(MessageInterface $message)
    {
        switch ($message->getAction()) {
            case MessageAction::MESSAGE_FINISHED_JOB:
                $body = $message->getBody();
                $isSuccess = $body['isSuccess'];
                $error = $body['error'];

                if (!$isSuccess) {
                    $this->emit('error', array($error));
                }

                $pid = $message->getFromPID();
                $jobId = isset($this->workers[$pid]['jobId']) ? $this->workers[$pid]['jobId'] : 0;

                if ($jobId) {
                    // 调度信息中记录得job信息为正在执行和超时
                    if (in_array($this->tasks[$jobId]['state'], [self::JOB_STATE_RUNNING , self::JOB_STATE_TIMEOUT])) {
                        /** @var JobInterface $job */
                        $job = $this->tasks[$jobId]['job'];
                        $this->scheduleWorker->workerFree($pid);
                        $this->workers[$pid]['jobId'] = 0;
                        $this->tasks[$jobId]['executeNum']++;
                        $this->tasks[$jobId]['state'] = self::JOB_STATE_CAN_EXECUTE;

                        if ((time() - $this->tasks[$jobId]['lastStartTime']) > $job->getTimeout()
                            && $job->onceExecuteWhenTimeout()) {
                            $this->dispatch($jobId, $this->tasks[$jobId]);
                        }
                    }
                }

        }
    }

    /**
     * 添加定时任务到定时任务列表中
     * @param JobInterface $job
     * @return $this
     */
    public function addJob(JobInterface $job) {
        $jobId = $this->createJobID($job);

        $this->tasks[$jobId] = [
            'job' => $job,
            'state' => self::JOB_STATE_CAN_EXECUTE,
            'executeNum' => 0,
            'firstExecuteTime' => 0,
            'lastStartTime' => 0
        ];

        return $this;
    }

    /**
     * 为对象生成唯一标识
     * @param $job
     * @return string
     */
    public function createJobID($job) {
        return md5(spl_object_hash($job));
    }
}