<?php


namespace Lan\Speed;

use Bunny\Channel;
use Bunny\Exception\ClientException;
use Bunny\Message;
use Lan\Speed\Exception\DaemonException;
use Lan\Speed\Exception\MessageFormatExcetion;
use Lan\Speed\Exception\SocketCreateException;
use Lan\Speed\Exception\SocketWriteException;
use Lan\Speed\Exception\WorkerCreateException;
use Lan\Speed\Impl\HandlerInterface;
use Lan\Speed\Impl\MessageInterface;
use React\EventLoop\Factory;
use React\Promise\Promise;


/**
 * Class Master
 * @package Lan\Speed
 *
 * @event message
 */
class Master extends Process implements HandlerInterface
{
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


    private $daemon = false;

    private $logger = null;

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
            $this->waitChildrenExit();
        });
    }

    public function waitChildrenExit() {
        while (($pid = pcntl_wait($status, WNOHANG)) > 0) {
            $this->removeWorker($pid);
        }
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
                $this->sendMessage($body['pid'], new \Lan\Speed\Message(MessageAction::MESSAGE_LAST));
                break;
            // 子进程已经准备好了，发送此消息
            case MessageAction::MESSAGE_QUIT_ME:
                $this->sendMessage($body['pid'], new \Lan\Speed\Message(MessageAction::MESSAGE_YOU_EXIT));
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
        if (isset($this->workers[$pid])) {
            if (isset($this->workers[$pid]['stream'])) {
                $this->workers[$pid]['stream']->end();
                return;
            }

            unset($this->workers[$pid]);
            $this->scheduleWorker->retireWorker($pid);
            $this->emit('workerExit', [$pid, $this]);
        }
    }

    /**
     * @return bool
     */
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

            $stream = new Stream($sockets[1], $this->eventLoop);
            $stream->on('data', array($stream, 'baseRead'));
            $stream->on('message', array($this, 'onReceive'));
            $stream->on('error', function ($error, $stream) {
                //$this->emit('error', array($error, $stream));
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
            $this->statistics[$pid] = $this->initStatistics();

        } else if ($pid == 0) {
            $this->cancelProcessTimer();
            $this->removeAllListeners();
            $this->removeAllSignal();


//            foreach ($this->workers as $worker) {
//                if (isset($worker['stream']) && !empty($worker['stream'])) {
//                    $worker['stream']->end();
//                }
//            }

            fclose($sockets[1]);

            unset(
                $sockets[1], $this->eventLoop, $this->statistics,
                $this->stashMessage, $this->scheduleWorker
            );

            $worker = $this->workerFactory->makeWorker($sockets[0]);
            $worker->run();

        } else {
            throw new WorkerCreateException();
        }

        $this->scheduleWorker->workerFree($pid);
        return $pid;
    }


    /**
     * 主进程启动，正式开始任务循环分配
     * @param bool $daemon
     * @throws DaemonException
     * @throws WorkerCreateException
     * @throws SocketCreateException
     * @throws SocketWriteException
     */
    public function run($daemon = true) {
        if ($daemon) {
            $this->daemon = true;
            $this->daemon();
        }

        if (!$this->eventLoop) {
           $this->eventLoop = Factory::create();
        }

        $this->init();
        $this->emit('start', [$this]);

        $this->state = self::STATE_RUNNING;
        $this->connection->setPrefetchCount()
            ->setMessageHandler($this)
            ->connect($this->eventLoop)
            ->then(function () {
                $this->state = self::STATE_RUNNING;
            }, function ($reason) {
                $this->emit('error', [$reason]);
                $this->stop();
            });


        while ($this->state == self::STATE_RUNNING) {
            try {
                $this->loop($this->blockTime);
            } catch (\Exception $ex) {
                if ($ex instanceof ClientException) {
                    $this->stop();
                    break;
                }
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
        $readyCount = 3;

        while ($readyCount > 0) {
            foreach ($this->workers as $pid => $worker) {
                $this->sendMessage($pid, new \Lan\Speed\Message(MessageAction::MESSAGE_LAST));
            }

            $this->loop(0.4);//阻塞1秒，等待子进程退出
            $this->waitChildrenExit();
            $readyCount--;
            if (count($this->workers) <= 0) {
                $this->state = self::STATE_SHUTDOWN;
                break;
            }
        }

    }

    /**
     * 将缓存消息情况，清空之前需要先把缓存的消息处理完毕
     */
    public function flushCacheMessage() {
        if (count($this->stashMessage) <= 0) {
            return;
        }

        $alertMessage = <<<EOT
Have some task in the cache, need to process. message count: {$this->stashMessage->count()}. it will take some time,please waiting...\n
EOT;

        $this->safeEcho($alertMessage);

        while ($this->state == self::STATE_FLUSH_CACHE && $this->stashMessage->count() > 0) {
            while ($this->scheduleWorker->hasAvailableWorker()) {
                $this->dispatchCacheMessage();
            }

            $this->loop(0.5);
        }
    }

    /**
     * 安全打印信息
     * @param $message
     */
    public function safeEcho($message) {
        if ($this->daemon) {
            if ($this->logger) {
                $this->logger->info($message);
            } else {
                fwrite(STDOUT, $message);
            }
        } else {
            fwrite(STDOUT, $message . PHP_EOL);
        }
    }

    /**
     * 异步时间循环处理器
     * @param $period
     */
    public function loop($period) {
        if ($period) {
            $this->processTimer = $this->eventLoop->addTimer($period, function ($timer) {
                $this->stopLoop();
            });
        }

        $this->eventLoop->run();
    }

    public function stopLoop() {
        $this->cancelProcessTimer();
        $this->eventLoop->stop();
    }

    /**
     * 停止进程
     */
    public function stop() {
        if ($this->state != self::STATE_RUNNING) {
            return;
        }

        /**
         * 如果当前连接已经打开，则需要关闭连接，然后终止程序
         */
        if ($this->connection->isConnected()) {
            $this->connection->disconnect()->then(function () {
                $this->state = self::STATE_FLUSH_CACHE;
                $this->stopLoop();
            });
        } else {
            // broker连接已经关闭， 则直接终止事件循环
            $this->state = self::STATE_FLUSH_CACHE;
            $this->stopLoop();
        }
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
        if (!$workerPid && $this->getWorkerCount() < $this->maxWorkerNum) {
            $workerPid = $this->createWorker();
        }

        if (!$workerPid) {
            $this->cacheMessage($message);
            return;
        }

        $this->scheduleWorker->workerBusy($workerPid);
        try {
            $this->sendMessage($workerPid, $message);
        } catch (\Exception $ex) {
            if ($ex instanceof SocketWriteException) {
                $this->cacheMessage($message);
            }
            $this->emit('error', array($ex));
        }

    }

    /**
     * 派发缓存消息
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

        } else if ($this->state == self::STATE_RUNNING) { // 如果只是临时断掉连接， 则需要重新开启连接
            if ($this->connection->isConnected()) {
                $this->connection->resume()->then(null, function ($ex) {
                    $this->emit('error', [$ex]);
                });
            }
        } else  if ($this->state == self::STATE_FLUSH_CACHE) {
            //$this->state = self::STATE_SHUTDOWN;
        }
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

        umask(0);
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
     * @throws SocketWriteException
     */
    public function sendMessage($workerPid, MessageInterface $message) {
        if (isset($this->workers[$workerPid]['stream'])) {
            /** @var Stream $stream */
            $stream = $this->workers[$workerPid]['stream'];
            if ($stream->isWritable()) {
                $message = serialize($message);
                $stream->baseWrite($message);
                $this->statistics[$workerPid]['receiveCount']++;
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
    public function onReceive($data, Stream $stream) {
        /** @var MessageInterface $message */
        $message = unserialize($data);

        if ($message instanceof \Lan\Speed\Message) {
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

            $newMessage = new \Lan\Speed\Message(MessageAction::MESSAGE_CONSUME, json_encode($messageArr));
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
            if ($isCache && $this->isReachedMaxCacheCount()) {
                $promise->always(function () {
                    if ($this->isReachedMaxCacheCount()) {
                        $this->connection->pause()->then(null, function ($ex) {
                            $this->emit('error', [$ex]);
                        });
                    }
                });
            }

        } catch (\Exception $ex) {
            $this->emit('error', [$ex]);
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


    public function cacheMessage(MessageInterface $message) {
        $this->stashMessage->push(serialize($message));
    }

    public function isReachedMaxCacheCount() {
        return $this->stashMessage->count() >= $this->maxCacheMessageCount;
    }
}