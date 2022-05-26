<?php


namespace Lan\Speed;


use Lan\Speed\BunnyClient as Client;
use Bunny\Channel;
use Lan\Speed\Exception\ConnectException;
use Lan\Speed\Exception\ConsumeQueuesException;
use Lan\Speed\Impl\HandlerInterface;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use function React\Promise\all;
use function React\Promise\reject;
use function React\Promise\resolve;

class Connection
{
    /** @var string 和broker连接断开 */
    const STATE_DISCONNECTED = 'disconnected';

    /** @var string 和broker保持连接 */
    const STATE_CONNECTED = 'connected';

    /** @var string 暂停接受broker的消息，此时channel被关闭中 */
    const STATE_PAUSING = 'pausing';

    /** @var string 暂停接受broker的消息，此时channel被关闭 */
    const STATE_PAUSED = 'paused';

    /** @var string 恢复接受broker的消息，此时channel正在打开中，恢复之后将状态置为connected */
    const STATE_RESUMING = 'resuming';

    /** @var Client $client  */
    private $client;

    /** @var array $options */
    private $options;

    /** @var LoopInterface $loop */
    private $loop;

    /**
     * @var int 消息中心每次投放消息数量
     */
    private $prefetchCount = 1;

    /**
     * @var array|\Closure 队列绑定信息
     */
    public $queues = array();

    /**
     * @var HandlerInterface $messageHandler 消息消费处理器
     */
    private $messageHandler;

    /**
     * @var Channel 连接渠道
     */
    private $channel;

    /**
     * @var bool 是否需要回复连接broker通道
     * 这里是防止以下情形:
     * 当暂停让broker推送消息客户端，此时相应的通道连接还没关闭(正在关闭中，指服务端已经关闭，只是还没收到反馈消息)，
     * 但位于缓存中的消息已经被处理完毕。这时又恢复连接。这时就导致逻辑不正确。为此用此标记来让通道关闭之后(收到相应的反馈)
     * 如果此标记为true,则恢复连接，否则处理后续
     */
    private $needResume = false;

    /**
     * @var string 通道当前连接状态，默认断开
     */
    private $state = self::STATE_DISCONNECTED;

    /**
     * Connection constructor.
     * @param array $options
     * @param array $queues
     * @throws ConsumeQueuesException
     */
    public function __construct($options = array(), $queues = array())
    {
        if (!$queues) {
            throw new ConsumeQueuesException();
        }

        $this->queues = $this->handler($queues);
        $this->options = $options;
    }

    /**
     * 队列绑定消费handler
     * @param $queues
     * @return array
     */
    public function handler($queues) {
        $handlerMap = array();
        foreach (array_unique($queues) as $queue) {
            $handlerMap[$queue] = function (\Bunny\Message $message, Channel $channel, Client $client) use ($queue) {
                if (!$this->messageHandler) {
                    return resolve();
                }

               return $this->messageHandler->consume($message, $channel, $client, $queue);
            };
        }

        return $handlerMap;
    }
    /**
     * 连接处理
     * @param LoopInterface $loop
     * @return PromiseInterface
     */
    public function connect(LoopInterface $loop) {
        $this->loop = $loop;
        $this->client = new Client($loop, $this->options);

        return $this->client->connect()->then(function (Client $client) {
            $this->state = self::STATE_CONNECTED;
            return $client->channel();

        }, function (\Exception $reason) {
            throw new ConnectException($reason->getMessage());

        })->then(function (Channel $channel) {
            return $channel->qos(0, $this->getPrefetchCount())
                ->then(function () use ($channel) {
                    return $channel;
                });

        })->then(function (Channel $channel) {
            $this->channel = $channel;
            return $this->bindConsumer($channel);

        });
    }

    /**
     * 断开连接
     * @return \React\Promise\FulfilledPromise|\React\Promise\Promise|PromiseInterface
     */
    public function disconnect() {
        if ($this->state == self::STATE_DISCONNECTED) {
            return resolve();
        }

        if (!$this->client->isConnected()) {
            $this->state = self::STATE_DISCONNECTED;
            $this->client = null;
            return resolve();
        }

        if ($this->channel) {
            return $this->channel->close()->then(function () {
                $this->channel = null;
                if ($this->client) {
                    return $this->client->disconnect()->then(function () {
                        $this->state = self::STATE_DISCONNECTED;
                        $this->client = null;
                        return resolve();
                    });
                } else {
                    return resolve();
                }
            });
        } else {
            if ($this->client) {
                return $this->client->disconnect()->then(function () {
                    $this->state = self::STATE_DISCONNECTED;
                    $this->client = null;
                    return resolve();
                });
            } else {
                return resolve();
            }
        }
    }

    /**
     * 关闭消息通道，但是不关闭与服务端的连接
     * @return \React\Promise\FulfilledPromise|\React\Promise\Promise|PromiseInterface
     */
    public function pause() {
        // 客户端和服务端broker 的连接已经关闭， 这时关闭通道没有意义
        if ($this->state != self::STATE_CONNECTED) {
            return resolve();
        }

        // 不需要重复关闭
        if (in_array($this->state, [self::STATE_PAUSING, self::STATE_PAUSED])) {
            return resolve();
        }

        $this->state = self::STATE_PAUSING;

        return $this->channel->close()
            ->then(function () {
                // 关闭成功，更新状态
                $this->state = self::STATE_PAUSED;
                $this->channel = null;

                // 如果此时已经有恢复连接的操作，但碍于通道正在关闭中，没有恢复，这里直接恢复连接
                if ($this->needResume) {
                    return $this->resume();
                }
            }, function ($ex) {
                // 连接失败
                if ($this->client->isConnected()) {
                    $this->state = self::STATE_CONNECTED;
                } else {
                    $this->state = self::STATE_DISCONNECTED;
                    $this->client = null;
                    $this->channel = null;
                }
                return reject($ex);
            });
    }

    /**
     * 恢复连接
     * @return \React\Promise\FulfilledPromise|\React\Promise\Promise|PromiseInterface
     */
    public function resume() {
        // 通道连接已经开启，再恢复没有意义
        if (!$this->isConnected()) {
            return resolve();
        }

        if (in_array($this->state, [
            self::STATE_CONNECTED, self::STATE_PAUSING, self::STATE_RESUMING
        ])) {
            if (!$this->needResume && $this->state == self::STATE_PAUSING) {
                $this->needResume = true;
            }
            return resolve();
        }

        $this->needResume = false;
        $this->state = self::STATE_RESUMING;

        return $this->client->channel()
            ->then(function (Channel $channel) {
                return $channel->qos(0, $this->getPrefetchCount())->then(function () use ($channel) {
                    return $channel;
                });
            })
            ->then(function (Channel $channel) {
                $this->channel = $channel;
                return $this->bindConsumer($this->channel);
            })
            ->then(function () {
                $this->state = self::STATE_CONNECTED;
            }, function ($ex) {
                return reject($ex);
            });

    }

    /**
     * 为每个队列绑定相应的处理handler
     * @param Channel $channel
     * @return \React\Promise\Promise|PromiseInterface|\React\Promise\RejectedPromise
     */
    public function bindConsumer(Channel $channel) {

        $promise = array();

        if (!$this->queues) {
            return reject(new ConsumeQueuesException());
        }

        foreach ($this->queues as $queue => $handler) {
            $promise[] = $channel->consume($handler, $queue);
        }

        return all($promise);
    }

    public function setPrefetchCount($count = 1) {
        $this->prefetchCount = $count <= 0 ? 1 : $count;
        return $this;
    }

    public function getPrefetchCount() {
        return $this->prefetchCount;
    }

    /**
     * 设置消息处理器
     * @param HandlerInterface $messageHandler
     * @return $this
     */
    public function setMessageHandler(HandlerInterface $messageHandler) {
        $this->messageHandler = $messageHandler;
        return $this;
    }

    /**
     * @return bool 当前通道连接是否开启
     */
    public function isConnected() {
        return $this->state != self::STATE_DISCONNECTED;
    }
}
