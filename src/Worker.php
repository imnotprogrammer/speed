<?php


namespace Lan\Speed;


use Lan\Speed\Exception\MessageFormatExcetion;

class Worker extends Process
{
    /** @var Stream $communication */
    private $communication;

    private $state = self::STATE_SHUTDOWN;

    protected $receiveMessageCount = 0;
    protected $finishedMessageCount = 0;
    protected $errCount = 0;

    /**
     * 子进程阻塞时间
     * @var int
     */
    private $blockPeriod = 60;

    public function __construct($stream)
    {
        parent::__construct();
        $this->setPid(posix_getpid());

        $this->communication = new Stream($stream, $this->getEventLoop());
        $this->communication->on('data', [$this, 'receiveMessage']);
        $this->communication->on('error', function (\Exception $error, Stream $stream) {
            $this->emit('error', [$error, $stream]);
        });
    }

    /**
     * 处理从父进程接收到的消息
     * @param MessageInterface $message
     * @return mixed|void
     */
    public function handleMessage(MessageInterface $message)
    {
        switch ($message->getAction()) {
            case MessageAction::MESSAGE_CONSUME:
                $this->emit('message', [$this, $message]);
                break;
            case MessageAction::MESSAGE_NEED_EXIT: break;
            case MessageAction::MESSAGE_CUSTOM:
            default:
        }
    }

    /**
     * 子进程挂起
     */
    public function run() {
        $this->state = self::STATE_RUNNING;
        $this->emit('start', [$this]);

        while ($this->state == self::STATE_RUNNING) {
            $this->getEventLoop()->addTimer($this->blockPeriod, function ($timer) {
                $this->getEventLoop()->stop();
            });

            try {
                $this->getEventLoop()->run();
            } catch (\Exception $ex) {
                $this->getEventLoop()->stop();
                $this->emit('error', [$ex, $this]);
            }
        }

        $this->communication->end();
        $this->removeEventLoop();
        $this->emit('end', [$this]);
        exit(0);
    }

    public function stop() {
        $this->state = self::STATE_SHUTDOWN;
        $this->getEventLoop()->stop();
    }

    /**
     * 向主进程发送消息
     * @param MessageInterface $message
     */
    public function sendMessage(MessageInterface $message) {
        if ($this->communication && $this->communication->isWritable()) {
            $this->communication->write(serialize($message));
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