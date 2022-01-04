<?php


namespace Lan\Speed\Impl;


use Bunny\Channel;
use Bunny\Message;
use Lan\Speed\BunnyClient;

interface HandlerInterface
{
    public function consume(Message $message, Channel $channel, BunnyClient $client, $queue);
}