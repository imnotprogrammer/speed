<?php


use Bunny\Client;

require './vendor/autoload.php';

$client = (new Client([
    'host' => '172.17.0.3',
    'vhost' => 'docs',
    'username' => 'rabbitmq_user',
    'password' => '123456'
]))->connect();

$channel = $client->channel();

$channel->queueDeclare('hello', false, false, false, false);
$channel->queueDeclare('world', false, false, false, false);
$channel->queueDeclare('fanout', false, false, false, false);

$channel->exchangeDeclare('direct_ex');
$channel->exchangeDeclare('fanout_ex', 'fanout');

$channel->queueBind('hello', 'direct_ex', 'hello');
$channel->queueBind('hello', 'direct_ex', 'hello_xx');
$channel->queueBind('world', 'direct_ex', 'world');
$channel->queueBind('fanout', 'fanout_ex');

function GetRandStr($length){
    //字符组合
    $str = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $len = strlen($str)-1;
    $randstr = '';
    for ($i=0;$i<$length;$i++) {
        $num=mt_rand(0,$len);
        $randstr .= $str[$num];
    }
    return $randstr;
}

$isEnd = false;
while (!$isEnd) {
    $rand = mt_rand(1, 300);
    for ($i = 0; $i < 500; $i++) {
        try {
            //$channel->publish(GetRandStr(12), [], '', 'hello');
            $channel->publish(GetRandStr(1000), [], 'fanout_ex');
            //$channel->publish(GetRandStr(1000), [], '', 'world');
            $isEnd = true;
        } catch (\Exception $ex) {
            $isEnd = true;
            break;
        }
    }
    sleep(1);
}
$channel->close();
$client->disconnect();