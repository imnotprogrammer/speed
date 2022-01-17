<?php

use Lan\Speed\Impl\JobInterface;

include 'vendor/autoload.php';

class job
{
    private $workerFactory;

    /**
     * @var \Lan\Speed\Task $master
     */
    private $master;

    private $jobList = [
        \Lan\Speed\jobs\Test::class,
    ];

    public function __construct() {
        $this->init();
    }

    public function init() {
        $this->createWorkeFactory();
        $this->createMaster();
    }

    /**
     * worker创建者
     */
    public function createWorkeFactory() {
        $this->workerFactory = new \Lan\Speed\WorkerFactory();
        $this->workerFactory->registerEvent('start', function (\Lan\Speed\TaskWorker $worker) {
            $worker->setName('php:job:worker:' . $worker->getPid());
        })->registerEvent('error', function (Exception $ex, \Lan\Speed\TaskWorker $worker) {
            var_dump($worker->getPid(), $ex->getMessage(), $ex->getTraceAsString());
            $worker->stop();
        })->addSignal(SIGUSR1, function ($signal, \Lan\Speed\TaskWorker $worker) {
            $worker->setEnd(true);
        })->setWorkerClass(\Lan\Speed\TaskWorker::class);
    }

    public function createMaster() {
        $master = new \Lan\Speed\Task($this->workerFactory);
        $master->setName('master:job')//->enableDaemon()
           // ->setMaxCacheMessageCount(2000)
            //->setMaxWorkerNum(10)
            ->addSignal(SIGINT, function ($signal) use ($master) {
                $master->stop();
            })->addSignal(SIGTERM, function ($signal) use ($master) {
                $master->stop();
            })->addSignal(SIGUSR1, function ($signal) use ($master){
                // TODO 监听信号
                //var_dump($master->stat());
            })->on('error', function (\Exception $ex) {
                var_dump($ex->getMessage(), $ex->getTraceAsString());
            })->on('workerExit', function ($pid, $master) {
                echo 'worker ', $pid, ' exit!!!', PHP_EOL;
            });
        $this->master = $master;
    }

    public function execute() {
        if ($this->jobList) {
            foreach (array_unique($this->jobList) as $job) {
                /** @var JobInterface $job */
                $job = new $job;
                $this->master->addJob($job);
            }

            $this->master->run();
        } else {
            $this->master->safeEcho('no job need to execute');
        }
    }
}

(new job())->execute();