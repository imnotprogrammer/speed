<?php

namespace Lan\Speed\Exception;

class SocketCreateException extends \Exception
{
    protected $code = 500;
    protected $message = 'create master with worker socket failed';

    public function __construct()
    {
        parent::__construct($this->message, $this->code, null);
    }
}