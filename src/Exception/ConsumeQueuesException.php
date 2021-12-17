<?php


namespace Lan\Speed\Exception;

class ConsumeQueuesException extends \Exception
{
    protected $message = 'Consumer queue need not null array';
    protected $code = 500;

    public function __construct($message = '', $code = 500)
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