<?php


namespace Lan\Speed;


class Message implements \Lan\Speed\Impl\MessageInterface
{
    private $action;
    private $body = array();

    public function __construct($action, $body = array()) {
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

    public function toArray() {
        return array(
            'action' => $this->getAction(),
            'body' => $this->getBody()
        );
    }
}