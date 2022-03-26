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
        $time = time();
        while(true) {
            sleep(1);
            if ((time() - $time) > 80) {
                break;
            }
        }
        sleep(1);
        var_dump('job start to execute');
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