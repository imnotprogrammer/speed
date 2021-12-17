<?php


namespace Lan\Speed\Impl;


class Message implements \Lan\Speed\MessageInterface
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

    public function arrTo($arr) {
        if (isset($arr['action']) && isset($arr['body'])) {
            return new self($arr['action'], $arr['body']);
        }

        return false;
    }
}