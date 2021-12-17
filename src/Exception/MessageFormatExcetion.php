<?php


namespace Lan\Speed\Exception;

class MessageFormatExcetion extends \Exception
{
    protected $message = 'Message format error';
    protected $code = 500;

    public function __construct()
    {
        parent::__construct($this->message, $this->code, null);
    }
}