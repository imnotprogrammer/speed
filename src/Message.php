<?php


namespace Lan\Speed;


class Message implements \Lan\Speed\Impl\MessageInterface
{
    /**
     * @var string|integer 消息类型
     */
    private $action;

    /**
     * @var array|object 消息内容
     */
    private $body;

    /**
     * @var int 消息发送进程id，0-表示为主进程发送
     */
    private $fromPid = 0;


    public function __construct($action, $body = array())
    {
        $this->action = $action;
        $this->body = $body;

    }

    public function getAction()
    {
        return $this->action;
    }

    public function getBody()
    {
        return $this->body;
    }

    public function toArray()
    {
        return array(
            'action' => $this->getAction(),
            'body' => $this->getBody()
        );
    }

    public function setFromPID($pid)
    {
        $this->fromPid = $pid;
    }

    public function getFromPid()
    {
        return $this->fromPid;
    }
}