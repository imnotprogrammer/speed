<?php


namespace Lan\Speed\Exception;

class SocketWriteException extends \Exception
{
    protected $code = 500;
    protected $message = 'Socket write message Failed';

    public function __construct($message = "", $code = 0)
    {
        if ($message) {
            $this->message = $message;
        }

        if ($code) {
            $this->code = $code;
        }

        parent::__construct($this->message, $this->code, null);
    }
}