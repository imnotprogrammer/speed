# Speed

 `speed`是一个用于多进程消费rabbitmq队列消息的`composer`包，其架构为主+从模式。主进程主要
负责消息的派发和统计信息，子进程主要用于处理相应的任务消息。其中子进程会根据当前任务量进行动态配发。
目前还在测试中，若要用于生产中，请做好测试。

### 准备

 - php版本高于>=5.6
 - 扩展需要安装`pcntl`, `sockets`, `posix`'
 - 建议安装`event`,`ev`扩展。

> PS: 目前在测试`libevent`扩展作为IO事件循环器时，会出现主进程与子进程交互消息时，消息丢失的问题，同时信号监听也会失效，子进程回收出现延迟，这些问题主要出现在子进程空闲退出的时候。所以不建议使用该扩展。

### 安装

推荐使用`composer`安装, 包还未上架，敬请期待

### 使用用例
1. 入门示例:
```php
$queues = [
    'hello',
    'world',
    'fanout'
];

$connection  = new \Lan\Speed\Connection([
    'host' => 'x.x.x.x',
    'username' => 'guest',
    'password' => 'guest',
    'vhost' => '/',
], $queues);

//$connection->setPrefetchCount(10);
$factory = new \Lan\Speed\WorkerFactory();

$factory->registerEvent('start', function (\Lan\Speed\Worker $worker) {
    $worker->setName('worker:consumer:' . $worker->getPid());
    //$worker->setMaxFreeTime(5); // 如果设置了空闲时间>0,则视为子进程可以空闲退出，
})->registerEvent('end', function (\Lan\Speed\Worker $worker) { // 子进程退出触发的end事件
//    $worker->sendMessage(new \Lan\Speed\Impl\Message(\Lan\Speed\MessageAction::MESSAGE_WORKER_EXIT, [
//        'pid' => $worker->getPid()
//    ]));
})->registerEvent('message', function (\Lan\Speed\Worker $worker, \Lan\Speed\Message $message) { // 消息处理事件，rabbitmq 转发给子进程的消息
    usleep(100000);
    $body = json_decode($message->getBody(), true);
    $body['time'] = intval(microtime(true) * 1000);
    $body['pid'] = $worker->getPid();

    //file_put_contents('consumed.log', json_encode($body).PHP_EOL, FILE_APPEND);
})->registerEvent('disconnect', function (\Lan\Speed\Worker $worker) { // IPC socket 连接断开
    echo sprintf('worker %s disconnect!', $worker->getPid());
})->registerEvent('error', function (Exception $ex, \Lan\Speed\Worker $worker) { // 子进程出现的异常
    var_dump($worker->getPid(), $ex->getMessage(), $ex->getTraceAsString());
    $worker->stop();
})->addSignal(SIGUSR1, function ($signal, \Lan\Speed\Worker $worker) {  // 添加信号处理handler
    
});

try {
    $master = new \Lan\Speed\Master($connection, $factory);

    $master->setName('master:dispatcher')
        ->setMaxCacheMessageCount(2000)
        ->setMaxWorkerNum(5)
        ->addSignal(SIGINT, function ($signal) use ($master) { // 主进程添加信号处理handler
            $master->stop();
        })->addSignal(SIGTERM, function ($signal) use ($master) {
            $master->stop();
        })->addSignal(SIGUSR1, function ($signal) use ($master){
            // TODO 监听信号
            var_dump($master->stat());
        })->on('error', function (\Exception $ex) { // 异常出现错误
            var_dump($ex->getMessage(), $ex->getTraceAsString());
        })->on('workerExit', function ($pid, $master) { // 子进程退出
            echo 'worker ', $pid, ' exit!!!', PHP_EOL;
        })->on('patrolling', function (\Lan\Speed\Master $master) { // 轮询，默认为60s一次
            echo 'patrolling'.PHP_EOL;
            $memorySize = memory_get_usage(true);
            if ($memorySize > 0) {
                $size = $memorySize / 1024 / 1024; //(M)
                var_dump($size);
            }

        });

    $master->run(true);
} catch (\Exception $ex) {
    var_dump([
        $ex->getMessage(),
        $ex->getTraceAsString()
    ]);

    $master->stop();
}
```
### TO DO
1. 消费者模型框架完成
2. 改进消息对象（TO DO）
### Built With

* [bunny/bunny](http://www.dropwizard.io/1.0.2/docs/) - rabbitmq  异步客户端
* [react/event-loop](http://www.dropwizard.io/1.0.2/docs/) - event loop
* [react/stream](http://www.dropwizard.io/1.0.2/docs/) - stream 

### License

This project is licensed under the MIT License - see the [LICENSE.md](LICENSE.md) file for details
