<?php


namespace Lan\Speed;

use Bunny\Channel;
use Bunny\Message;
use Lan\Speed\Exception\DaemonException;
use Lan\Speed\Exception\MessageFormatExcetion;
use Lan\Speed\Exception\SocketCreateException;
use Lan\Speed\Exception\SocketWriteException;
use Lan\Speed\Exception\WorkerCreateException;
use React\Promise\Promise;
use function React\Promise\reject;


/**
 * Class Master
 * @package Lan\Speed
 *
 * @event message
 */
class Master extends Process implements HandlerInterface
{
    /** @var int 清除缓存状态信息 */
    const STATE_FLUSH_CACHE = 3;

    /** @var WorkerFactory  进程创建工厂 */
    private $workerFactory;

    /** @var Connection 与broker保持的连接数 */
    private $connection;

    /** @var int 主进程当前状态 */
    private $state = self::STATE_SHUTDOWN;

    /** @var ScheduleWorker $scheduleWorker 进程调度器 */
    private $scheduleWorker = null;

    /** @var int 阻塞事件，一个阻塞周期默认为60秒, 和巡逻时间周期一致 */
    private $blockTime = 60;

    /**
     * @var array 子进程信息
     * 格式:    array(
                   "pid" => [
     *                  'stream' => ''    // react/stream
     *              ]
     *         )
     *  当进程的创建和退出，此属性会动态更新，其有一个依赖项受调度器
     */
    protected $workers = array();

    /**
     * @var int 最大工作子进程数
     */
    protected $maxWorkerNum = 2;

    /**
     * @var int 消费完成数
     */
    private $consumedCount = 0;

    /**
     * @var int 收到消息中心投放的消息数
     */
    private $receiveCount = 0;

    /**
     * 缓存消息
     * @var \SplDoublyLinkedList $stashMessage
     */
    private $stashMessage;

    /**
     * @var int $maxCacheMessageCount 缓存消息最大数量
     */
    private $maxCacheMessageCount = 2000;

    /**
     * @var array $statistics 统计数据
     * 以进程id为key, value 为统计信息一个数组
     * 格式:
     * array(
     *     '$workerPid' => array (
     *         'receiveCount' => 0,
     *         'consumedCount' => 0,
     *         ...
     *      )
     * )
     */
    private $statistics = array();

    /**
     * 取消阻塞定时器
     * @var null
     */
    private $processTimer = null;


    /**
     * Master constructor.
     * @param Connection $connection
     * @param WorkerFactory $workerFactory
     */
    public function __construct(Connection $connection, WorkerFactory $workerFactory)
    {
        $this->connection = $connection;
        $this->workerFactory = $workerFactory;
        $this->scheduleWorker = new ScheduleWorker();
        $this->stashMessage = new \SplDoublyLinkedList();
        parent::__construct();
        $this->init();
    }

    /**
     * @return int
     */
    public function getMaxWorkerNum()
    {
        return $this->maxWorkerNum;
    }

    /**
     * @param int $maxWorkerNum
     */
    public function setMaxWorkerNum($maxWorkerNum)
    {
        $this->maxWorkerNum = $maxWorkerNum;
        return $this;
    }

    /**
     * @return int
     */
    public function getMaxCacheMessageCount()
    {
        return $this->maxCacheMessageCount;
    }

    /**
     * @param int $maxCacheMessageCount
     */
    public function setMaxCacheMessageCount($maxCacheMessageCount)
    {
        $this->maxCacheMessageCount = $maxCacheMessageCount;
        return $this;
    }

    private function init() {
        $this->addSignal(SIGCHLD, function ($signal)  {
            while ($pid = pcntl_wait($status, WNOHANG)) {
                $this->removeWorker($pid);
                if (count($this->getWorkers()) <= 0) {
                    break;
                }
            }
        });
    }

    /**
     * 消息处理(子进程发送的消息)
     * 消息类型目前有以下几种:
     * MESSAGE_FINISHED:    子进程处理消息完成，回调给主进程
     * MESSAGE_READY_EXIT:  子进程退出之前，会给主进程发送消息告知，
     * MESSAGE_QUIT_ME:     子进程收到 MESSAGE_LAST后，会回执此消息
     * @param MessageInterface $message
     * @return mixed|void
     */
    public function handleMessage(MessageInterface $message)
    {
        $body = $message->getBody();
        switch ($message->getAction()) {
            // 子进程处理任务完毕
            case MessageAction::MESSAGE_FINISHED:
                $this->consumedCount++;
                if (isset($body['pid']) && $body['pid']) {
                    $this->scheduleWorker->workerFree($body['pid']);
                    $this->statistics[$body['pid']]['consumedCount']++;
                }
                $this->dispatchCacheMessage();
                break;

            // 子进程准备退出
            case MessageAction::MESSAGE_READY_EXIT:
                $this->scheduleWorker->retireWorker($body['pid']);
                $this->sendMessage($body['pid'], new \Lan\Speed\Impl\Message(MessageAction::MESSAGE_LAST));
                break;
            // 子进程已经准备好了，发送此消息
            case MessageAction::MESSAGE_QUIT_ME:
                $this->sendMessage($body['pid'], new \Lan\Speed\Impl\Message(MessageAction::MESSAGE_YOU_EXIT));
                break;
            case MessageAction::MESSAGE_CUSTOM:
                //TODO 自定义消息处理
                break;
            default:break;
        }
    }

    /**
     * 移除进程或某个进程信息
     * @param int $pid
     */
    public function removeWorker($pid = 0) {
        if ($pid) {
            if (isset($this->workers[$pid])) {
                unset($this->workers[$pid]['stream']);
                unset($this->workers[$pid]);
                $this->scheduleWorker->retireWorker($pid);
                $this->emit('workerExit', [$pid, $this]);
            }
        } else {
            foreach ($this->workers as $pid => $worker) {
                unset($worker['stream']);
                unset($this->workers[$pid]);
                $this->scheduleWorker->retireWorker($pid);
            }
        }
    }

    public function isMaxLimit() {
        if ($this->maxWorkerNum < 0) {
            return false;
        }

        return $this->getWorkerCount() >= $this->maxWorkerNum;
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
            $stream = new Stream($sockets[1], $this->getEventLoop());
            $stream->on('data', [$this, 'receive']);
            $stream->on('error', function ($error, $stream) {
                $this->emit('error', [$error, $stream]);
            });

            $stream->on('close', function ($stream) use ($pid) {
                // 告诉相应子进程，由于父进程与子进程通信通道被关闭，现在需要停止运行子进程
                //$this->sendMessage($pid, new \Lan\Speed\Impl\Message(MessageAction::MESSAGE_LAST));
            });

            $this->workers[$pid]['stream'] = $stream;
            $this->statistics[$pid] = $this->initStatistics();

        } else if ($pid == 0) {
            fclose($sockets[1]);
            unset($sockets[1]);
            unset($this->stashMessage);
            unset($this->scheduleWorker);
            unset($this->statistics);

            $this->cancelProcessTimer();
            $this->removeAllListeners();
            $this->removeAllSignal();
            //$this->removeWorker();

            unset($this->eventLoop);
            unset($this->workers);

            $worker = $this->workerFactory->makeWorker($sockets[0]);
            $worker->run();

        } else {
            throw new WorkerCreateException();
        }

        return $pid;
    }


    /**
     * 主进程启动，正式开始任务循环分配
     * @param bool $daemon
     * @throws DaemonException
     * @throws WorkerCreateException
     */
    public function run($daemon = true) {
        if ($daemon) {
            $this->daemon();
        }

        $this->state = self::STATE_RUNNING;

        if (!$this->eventLoop) {
            return;
        }

       $this->emit('start', [$this]);
        $this->connection->setPrefetchCount(10)
            ->setMessageHandler($this)
            ->connect($this->eventLoop)->then()
            ->then(null, function ($reason) {
                $this->emit('error', [$reason]);
                $this->state = self::STATE_SHUTDOWN;
            });

        while ($this->state == self::STATE_RUNNING) {
            try {
                $this->loop($this->blockTime);
            } catch (\Exception $ex) {
                $this->eventLoop->stop();
                $this->emit('error', [$ex]);
            }

            $this->emit('patrolling', [$this]);
        }

        $this->flushCacheMessage();
        $this->clearWorkers();

        if ($this->connection && $this->connection->isConnected()) {
            $this->connection->disconnect();
        }

        $this->emit('end', [$this]);
    }

    /**
     * 退出所有子进程
     * @throws SocketWriteException
     */
    public function clearWorkers() {
        if ($this->state == self::STATE_SHUTDOWN) {
            foreach ($this->workers as $pid => $worker) {
                // 安全退出

                $this->sendMessage($pid, new \Lan\Speed\Impl\Message(MessageAction::MESSAGE_LAST));
            }

            while (count($this->workers) > 0) {
                $this->loop(0.1);//阻塞100毫秒，等待子进程退出
            }

            $this->cancelProcessTimer();
        }
    }

    /**
     * 将缓存消息情况，清空之前需要先把缓存的消息处理完毕
     * @throws SocketCreateException
     * @throws WorkerCreateException
     */
    public function flushCacheMessage() {
        $this->printAlertMessage();
        while ($this->state == self::STATE_FLUSH_CACHE
            && $this->stashMessage->count() > 0
        ) {
            while ($this->scheduleWorker->hasAvailableWorker()) {
                $this->dispatchCacheMessage();
            }

            $this->loop(0.5);
        }
        $this->state = self::STATE_SHUTDOWN;
    }

    public function printAlertMessage() {
        echo $this->getAlertMessage();
    }

    public function getAlertMessage() {
        return sprintf("message have some cache, need to process. message count: %s. it will take some time,please waiting...\n", count($this->stashMessage));
    }

    /**
     * 异步时间循环处理器
     * @param $period
     */
    public function loop($period) {
        if ($period) {
            $this->processTimer = $this->eventLoop->addTimer($period, function ($timer) {
                $this->eventLoop->stop();
                $this->processTimer = null;
            });
        }

        try {
            $this->eventLoop->run();
        } catch (\Exception $ex) {
            $this->emit('error', [$ex]);
        }
    }

    /**
     * 停止进程
     */
    public function stop() {
        if ($this->state != self::STATE_RUNNING) {
            return;
        }

        $this->connection->disconnect()->then(function () {
            $this->state = self::STATE_FLUSH_CACHE;
            $this->cancelProcessTimer();
            $this->eventLoop->stop();
        });
    }

    /**
     * 移除阻塞定时器
     */
    public function cancelProcessTimer() {
        if ($this->processTimer) {
            $this->eventLoop->cancelTimer($this->processTimer);
            $this->processTimer = null;
        }
    }

    /**
     * 派发消息给子进程
     * @param MessageInterface $message
     * @throws SocketCreateException
     * @throws SocketWriteException
     * @throws WorkerCreateException
     */
    public function dispatch(MessageInterface $message) {
        $workerPid = $this->scheduleWorker->allocate();
        if ($workerPid) {
            $this->sendMessage($workerPid, $message);
        } else if (count($this->workers) < $this->maxWorkerNum) {
            $workerPid = $this->createWorker();
            $this->scheduleWorker->workerFree($workerPid);
            $this->scheduleWorker->workerAllocate($workerPid);
            $this->sendMessage($workerPid, $message);
        }
    }

    /**
     * @throws SocketCreateException
     * @throws WorkerCreateException
     */
    public function dispatchCacheMessage() {
        if (!$this->scheduleWorker->hasAvailableWorker()) {
            return;
        }

        $count = $this->stashMessage->count();
        if ($count > 0) {
            try {
                $message = unserialize($this->stashMessage->offsetGet(0));
                $this->stashMessage->shift();
                $this->dispatch($message);
            } catch (\Exception $ex) {
                $this->emit('error', [$ex]);
            }

        } else if ($this->state == self::STATE_RUNNING) {
            if ($this->connection->isConnected()) {
                $this->connection->resume()->then(null, function ($ex) {
                    $this->emit('error', [$ex]);
                });
            }
        }
    }

   // public function termite

    public function cacheMessage(MessageInterface $message) {
         $this->stashMessage->push(serialize($message));
    }

    public function isReachedMaxCahcheCount() {
        return $this->stashMessage->count() >= $this->maxCacheMessageCount;
    }

    /**
     * 进程后台挂起
     * @throws DaemonException
     * @throws WorkerCreateException
     */
    public function daemon() {
        if (!extension_loaded('pcntl')) {
            throw new DaemonException();
        }

        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new WorkerCreateException();
        } elseif ($pid > 0) {
            // 让由用户启动的进程退出
            exit(0);
        }

        // 建立一个有别于终端的新session以脱离终端
        posix_setsid();

        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new WorkerCreateException();
        } elseif ($pid > 0) {
            // 父进程退出, 剩下子进程成为最终的独立进程
            exit(0);
        }
    }

    /**
     * 向子进程发送消息
     * @param $workerPid
     * @param MessageInterface $message
     */
    public function sendMessage($workerPid, MessageInterface $message) {
        if (isset($this->workers[$workerPid]['stream'])) {
            /** @var Stream $stream */
            $stream = $this->workers[$workerPid]['stream'];
            if ($stream->isWritable()) {
                $stream->write(serialize($message));
                $this->statistics[$workerPid]['receiveCount']++;
                $this->scheduleWorker->workerBusy($workerPid);
            } else {
                throw new SocketWriteException();
            }
        }
    }

    /**
     * 收到子进程的消息
     * @param $data
     * @param Stream $stream
     * @throws MessageFormatExcetion
     */
    public function receive($data, Stream $stream) {
        /** @var MessageInterface $message */
        $message = unserialize($data);

        if ($message instanceof \Lan\Speed\Impl\Message) {
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

    /**
     * 统计数据
     * @return array
     */
    public function stat() {
        $workerConsumed = 0;
        foreach ($this->statistics as $key => $statistic) {
            $this->statistics[$key]['state'] = $this->scheduleWorker->getWorkerState($key);
            $workerConsumed += $statistic['consumedCount'];
        }

        return array(
            'countChildWorkers' => count($this->workers),
            'consumedMessageCount' => $this->consumedCount,
            'receivedMessageCount' => $this->receiveCount,
            'cacheMessage' => count($this->stashMessage),
            'freeWorkerCount' => $this->scheduleWorker->getFreeWorker(),
            'statistics' => $this->statistics,
            'workerConsumedCount' => $workerConsumed
        );
    }

    /**
     * 进程消费处理器
     * @param Message $message
     * @param Channel $channel
     * @param BunnyClient $client
     * @param $queue
     * @return bool|\React\Promise\PromiseInterface|\React\Promise\RejectedPromise
     */
    public function consume(Message $message, Channel $channel, BunnyClient $client, $queue)
    {
        try {
            $messageArr = array(
                'routingKey' => $message->routingKey ?: '',
                'consumerTag' => $message->consumerTag,
                'exchange' => $message->exchange,
                'body' => $message->content,
                'queue' => $queue,
            );

            $newMessage = new \Lan\Speed\Impl\Message(MessageAction::MESSAGE_CONSUME, json_encode($messageArr));
            // 子进程数达到最大限制
            $isCache = $this->isMaxLimit() && !$this->scheduleWorker->hasAvailableWorker();
            if ($isCache) {
                $this->cacheMessage($newMessage);
            } else {
                $this->dispatch($newMessage);
            }

            $this->receiveCount++;
            /**
             * @var Promise $promise
             */
            $promise =  $channel->ack($message);

            // 缓存消息达到最大缓存数量，停止从消息中心broker中派发消息
            if ($isCache && $this->isReachedMaxCahcheCount()) {
                $promise->always(function () {
                    if ($this->isReachedMaxCahcheCount()) {
                        $this->connection->pause()->then(null, function ($ex) {
                            $this->emit('error', [$ex]);
                        });
                    }
                });
            }

            return $promise;

        } catch (\Exception $ex) {
            return reject($ex);
        }
    }

    /**
     * @return array
     */
    public function getWorkers() {
        return $this->workers;
    }

    /**
     * @return int
     */
    public function getWorkerCount() {
        return count($this->workers);
    }

    /**
     * 初始化进程统计消息
     * @return int[]
     */
    public function initStatistics() {
        return array(
            'receiveCount' => 0,
            'consumedCount' => 0
        );
    }
}