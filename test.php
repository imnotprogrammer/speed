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
    'host' => '172.17.0.3',
    'username' => 'rabbitmq_user',
    'password' => '123456',
    'vhost' => 'docs',
], $queues);
$logger = new \Monolog\Logger('queue');
$handler = new \Monolog\Handler\RotatingFileHandler('queue.log', 15);
$handler->setFormatter(new \Monolog\Formatter\JsonFormatter());
$logger->pushHandler($handler);
$logger->pushProcessor(function ($record) {
    if (isset($record['datetime'])) {
        $datetime = $record['datetime'];
        if ($datetime instanceof DateTime) {
            $datetime = $datetime->format('Y-m-d H:i:s.u');
            $record['datetime'] = $datetime;
        }
    }

    return $record;
});

$factory = new \Lan\Speed\WorkerFactory();

$factory->registerEvent('start', function (\Lan\Speed\Worker $worker) {
    $worker->setName('worker:consumer:' . $worker->getPid());
    $worker->setMaxFreeTime(5);
})->registerEvent('end', function (\Lan\Speed\Worker $worker) {
//    $worker->sendMessage(new \Lan\Speed\Impl\Message(\Lan\Speed\MessageAction::MESSAGE_WORKER_EXIT, [
//        'pid' => $worker->getPid()
//    ]));
})->registerEvent('message', function (\Lan\Speed\Worker $worker, \Lan\Speed\Message $message) use ($logger) {
    usleep(100000);

    $logger->info('consume message', [
        'service' => 'consume',
        'data' => $message->toArray()
    ]);
})->registerEvent('end', function (\Lan\Speed\Worker $worker) {
    echo sprintf('worker %s exit! '.PHP_EOL, $worker->getPid());
})->registerEvent('disconnect', function (\Lan\Speed\Worker $worker) {
    echo sprintf('worker %s disconnect!', $worker->getPid());
})->registerEvent('error', function (Exception $ex, \Lan\Speed\Worker $worker) {
    var_dump($worker->getPid(), $ex->getMessage(), $ex->getTraceAsString());
    $worker->stop();
})->addSignal(SIGUSR1, function ($signal, \Lan\Speed\Worker $worker) {
     //TODO DO SOMETHING
});

try {
    $master = new \Lan\Speed\Master($connection, $factory);

    $master->setName('master:dispatcher')
        ->setWrapMessageHandler(function (\Bunny\Message $message, $queue, \Lan\Speed\Master $master) {
            return new \Lan\Speed\Message(\Lan\Speed\MessageAction::MESSAGE_CONSUME, [
                'message' => $message,
                'queue' => $queue
            ]);
        })
        ->setAutoClear(false)
        ->setMaxCacheMessageCount(2000)
        ->setMaxWorkerNum(20)
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
            echo 'patrolling'.PHP_EOL;
            $memorySize = memory_get_usage(true);
            if ($memorySize > 0) {
                $size = $memorySize / 1024 / 1024; //(M)
                var_dump($size);
            }

        });

    $master->run();
} catch (\Exception $ex) {
    var_dump([
        $ex->getMessage(),
        $ex->getTraceAsString()
    ]);

    $master->stop();
}




