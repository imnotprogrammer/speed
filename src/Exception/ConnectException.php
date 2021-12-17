<?php


namespace Lan\Speed\Exception;

class ConnectException extends \Exception
{
    //protected $message = 'Connection amqp broker failed';
    protected $code;

    public function __construct($message)
    {
        parent::__construct($message, $this->code, null);
    }
}