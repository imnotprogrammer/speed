<?php

require_once 'vendor/autoload.php';

//set_error_handler(function ($errorNo, $errStr, $errFile, $errLine, $errContext) {
//
//    throw new \Exception(sprintf('message:%s, file:%s,line:%s,context:%s', $errStr, $errFile, $errLine, $errContext), $errorNo);
//});
//
//set_exception_handler(function (Exception $ex) {
//    var_dump($ex->getMessage(), $ex->getLine(), $ex->getTraceAsString());
//});
date_default_timezone_set('Asia/Shanghai');
$rabbitmqMap = [
    //['exchange' => 'xx', 'que']
];
$queues = [
    'hello',
    'world',
    'fanout'
];

$connection  = new \Lan\Speed\Connection([
    'host' => '192.168.123.126',
    'username' => 'rabbitmq_user',
    'password' => '123456',
    'vhost' => 'docs',
], $queues);

//$connection->setPrefetchCount(10);
$factory = new \Lan\Speed\WorkerFactory();

$factory->registerEvent('start', function (\Lan\Speed\Worker $worker) {
    $worker->setName('worker:consumer:' . $worker->getPid());
    //$worker->setMaxFreeTime(5);
})->registerEvent('end', function (\Lan\Speed\Worker $worker) {
//    $worker->sendMessage(new \Lan\Speed\Impl\Message(\Lan\Speed\MessageAction::MESSAGE_WORKER_EXIT, [
//        'pid' => $worker->getPid()
//    ]));
})->registerEvent('message', function (\Lan\Speed\Worker $worker, \Lan\Speed\Message $message) {
    usleep(100000);
    $body = json_decode($message->getBody(), true);
    $body['time'] = intval(microtime(true) * 1000);
    $body['pid'] = $worker->getPid();

    //file_put_contents('consumed.log', json_encode($body).PHP_EOL, FILE_APPEND);
})->registerEvent('end', function (\Lan\Speed\Worker $worker) {
    echo sprintf('worker %s exit! '.PHP_EOL, $worker->getPid());
})->registerEvent('disconnect', function (\Lan\Speed\Worker $worker) {
    echo sprintf('worker %s disconnect!', $worker->getPid());
})->registerEvent('error', function (Exception $ex, \Lan\Speed\Worker $worker) {
    var_dump($worker->getPid(), $ex->getMessage(), $ex->getTraceAsString());
    $worker->stop();
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
        })->on('patrolling', function (\Lan\Speed\Master $master) {
            //file_put_contents('stat.log', date('Y-m-d H:i:s', time()) . var_export($master->stat(), true) .PHP_EOL, FILE_APPEND);
        });

    $master->run(false);
} catch (\Exception $ex) {
    var_dump([
        $ex->getMessage(),
        $ex->getTraceAsString()
    ]);

    $master->stop();
}




