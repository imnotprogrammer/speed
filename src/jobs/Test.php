<?php


namespace Lan\Speed\jobs;


use Lan\Speed\Impl\JobInterface;

class Test implements JobInterface
{

    private $timeout = 70;
    private $alwaysExecute = false;
    private $onceExecute = false;

    public function isRunAble()
    {
        return true;
    }

    public function run()
    {
        sleep($this->timeout+2);
        var_dump(1);
    }

    public function getTimeout()
    {
        return $this->timeout;
    }

    public function alwaysExecuteWhenTimeout()
    {
        return $this->alwaysExecute;
    }

    public function onceExecuteWhenTimeout()
    {
        return $this->onceExecute;
    }
}