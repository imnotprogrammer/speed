<?php


namespace Lan\Speed\Exception;

class WorkerCreateException extends \Exception
{
    protected $message = 'Fork worker failed';
    protected $code = 500;

    public function __construct()
    {
        parent::__construct($this->message, $this->code, null);
    }
}