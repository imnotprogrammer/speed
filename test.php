<?php

require_once 'vendor/autoload.php';

$queues = [
    'hello',
    'world',
    'fanout'
];

$connection  = new \Lan\Speed\Connection([
    'host' => '192.168.123.142'
], $queues);

$factory = new \Lan\Speed\WorkerFactory();

$factory->registerEvent('start', function (\Lan\Speed\Worker $worker) {
    $worker->setName('worker:consumer:' . $worker->getPid());
})->registerEvent('end', function (\Lan\Speed\Worker $worker) {
    $worker->sendMessage(new \Lan\Speed\Impl\Message(\Lan\Speed\MessageAction::MESSAGE_WORKER_EXIT, [
        'pid' => $worker->getPid()
    ]));
})->registerEvent('message', function (\Lan\Speed\Worker $worker, \Lan\Speed\Impl\Message $message) {
    //usleep(200000);
    $worker->sendMessage(new \Lan\Speed\Impl\Message(\Lan\Speed\MessageAction::MESSAGE_FINISHED, [
        'pid' => $worker->getPid()
    ]));
});

$master = new \Lan\Speed\Master($connection, $factory);
$master->setName('master:dispatcher')
    ->setMaxCacheMessageCount(2000)
    ->setMaxWorkerNum(4)
    ->addSignal(SIGCHLD, function ($signal) use ($master) {
        while ($pid = pcntl_wait($status, WNOHANG)) {
            $master->removeWorker($pid);
            if (count($master->getWorkers()) <= 0) {
                break;
            }
        }
})->addSignal(SIGINT, function ($signal) use ($master) {
    $master->stop();
})->addSignal(SIGTERM, function ($signal) use ($master) {
    $master->stop();
})->addSignal(SIGUSR1, function ($signal) use ($master){
    print_r($master->stat());
})->on('error', function (\Exception $ex) {
    var_dump($ex->getMessage());
});

$master->run(false);