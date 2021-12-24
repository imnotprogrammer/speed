<?php

require_once 'vendor/autoload.php';

$rabbitmqMap = [
    //['exchange' => 'xx', 'que']
];
$queues = [
    'hello',
    'world',
    'fanout'
];

$connection  = new \Lan\Speed\Connection([
    'host' => '192.168.123.167',
    'username' => 'rabbitmq_user',
    'password' => '123456',
    'vhost' => 'docs',
], $queues);

//$connection->setPrefetchCount(10);
$factory = new \Lan\Speed\WorkerFactory();

$factory->registerEvent('start', function (\Lan\Speed\Worker $worker) {
    $worker->setName('worker:consumer:' . $worker->getPid());
    $worker->setMaxFreeTime(5);
})->registerEvent('end', function (\Lan\Speed\Worker $worker) {
//    $worker->sendMessage(new \Lan\Speed\Impl\Message(\Lan\Speed\MessageAction::MESSAGE_WORKER_EXIT, [
//        'pid' => $worker->getPid()
//    ]));
})->registerEvent('message', function (\Lan\Speed\Worker $worker, \Lan\Speed\Impl\Message $message) {
    usleep(100000);
    $body = json_decode($message->getBody(), true);
    $body['time'] = intval(microtime(true) * 1000);
    $body['pid'] = $worker->getPid();

    //file_put_contents('consumed.log', json_encode($body).PHP_EOL, FILE_APPEND);
})->registerEvent('disconnect', function () {

});

try {
    $master = new \Lan\Speed\Master($connection, $factory);

    $master->setName('master:dispatcher')
        ->setMaxCacheMessageCount(2000)
        ->setMaxWorkerNum(5)
        ->addSignal(SIGINT, function ($signal) use ($master) {
            $master->stop();
        })->addSignal(SIGTERM, function ($signal) use ($master) {
            $master->stop();
        })->addSignal(SIGUSR1, function ($signal) use ($master){
            // TODO 监听信号
            var_dump($master->stat());
        })->on('error', function (\Exception $ex) {
            var_dump($ex->getMessage(), $ex->getTraceAsString());
        })->on('workerExit', function ($pid, $master) {
            echo 'worker ', $pid, ' exit!!!', PHP_EOL;
        });

    $master->run(false);
} catch (\Exception $ex) {
    var_dump($ex->getMessage(), $ex->getTraceAsString());
}




