<?php


namespace Lan\Speed\Exception;

class DaemonException extends \Exception
{
    protected $code = 500;
    protected $message = 'Current system dont support daemon mode';

    public function __construct()
    {
        parent::__construct($this->message, $this->code, null);
    }
}