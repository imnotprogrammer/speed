<?php

use Bunny\Channel;
use Lan\Speed\BunnyClient as Client;
use Lan\Speed\Exception\ConnectException;

require_once 'vendor/autoload.php';

$eventLoop = \React\EventLoop\Factory::create();

$eventLoop->addSignal(SIGUSR1, function ($signal) {
    var_dump($signal);
});

$client = new \Lan\Speed\BunnyClient($eventLoop, [
    'host' => '192.168.123.167',
    'vhost' => 'docs',
    'username' => 'rabbitmq_user',
    'password' => '123456'
]);

$client->connect()->then(function (Client $client) {
    return $client->channel();

}, function (\Exception $reason) {
    throw new ConnectException($reason->getMessage());

})->then(function (Channel $channel) {
    return $channel->qos(0, 10)
        ->then(function () use ($channel) {
            return $channel;
        });

})->then(function (Channel $channel) {
    return $channel->consume(function (\Bunny\Message $message, Channel $channel, Client $client) use ($channel) {
        var_dump(1);

    }, 'fanout');

});

function createWorker(callable $func) {
    $pid = pcntl_fork();
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM,STREAM_IPPROTO_TCP);

    if ($pid == 0) {
        fclose($sockets[0]);

        while (true) {

        }
    } else if ($pid > 0) {

    }
}
while(true) {
    $eventLoop->addTimer(0.5, function ($timer) use ($eventLoop) {
        $eventLoop->cancelTimer($timer);
        $eventLoop->stop();
    });

    $eventLoop->run();
}