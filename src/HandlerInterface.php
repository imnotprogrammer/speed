<?php


namespace Lan\Speed;


use Bunny\Channel;
use Bunny\Message;

interface HandlerInterface
{
    public function consume(Message $message, Channel $channel, BunnyClient $client, $queue);
}