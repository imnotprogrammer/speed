<?php

use Bunny\Channel;
use Lan\Speed\BunnyClient as Client;
use Lan\Speed\Exception\ConnectException;
use Lan\Speed\Exception\SocketCreateException;

require_once 'vendor/autoload.php';
$curl = curl_init();

curl_setopt_array($curl, array(
    CURLOPT_URL => 'http://mexico-uat.loancloudmx.net/v21/score/model/externalCalculate?sign=4e430f61746ed7b4839795446a9d91fc&timestamp=1640939639',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT =>20,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => array('identifierCode' => 'fd1ddb1a1ec805e3cff8e29301706c14','data' => '1','sign' => '4e430f61746ed7b4839795446a9d91fc','timestamp' => '1640939639'),
    CURLOPT_HTTPHEADER => array(
        //'Accept:  application/json',
        'Accept-Encoding:  gzip, deflate, br',
        'Accept-Language:  zh-CN,zh;q=0.9',
        'Content-Type:  application/json',
        'Cookie:  PHPSESSID=2ncc2mkdm8t7l3ssmqn24qnqc3',
        'Current-Language:  zh-CN',
        'Host:  mexico-uat.loancloudmx.net',
        'Origin:  https://mexico-uat.loancloudmx.net',
        'Referer:  https://mexico-uat.loancloudmx.net/console/',
        'sec-ch-ua-platform:  "Windows"',
        'Sec-Fetch-Dest:  empty',
        'Sec-Fetch-Mode:  cors',
        'Sec-Fetch-Site:  same-origin',
        'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.110 Safari/537.36',
        'X-Requested-With:  XMLHttpRequest'
    ),
));

$response = curl_exec($curl);

curl_close($curl);
echo $response;die();
//$eventLoop = \React\EventLoop\Factory::create();
//
//$eventLoop->addSignal(SIGUSR1, function ($signal) {
//    var_dump($signal);
//});
//
//$client = new \Lan\Speed\BunnyClient($eventLoop, [
//    'host' => '192.168.123.167',
//    'vhost' => 'docs',
//    'username' => 'rabbitmq_user',
//    'password' => '123456'
//]);
//
//$client->connect()->then(function (Client $client) {
//    return $client->channel();
//
//}, function (\Exception $reason) {
//    throw new ConnectException($reason->getMessage());
//
//})->then(function (Channel $channel) {
//    return $channel->qos(0, 10)
//        ->then(function () use ($channel) {
//            return $channel;
//        });
//
//})->then(function (Channel $channel) {
//    return $channel->consume(function (\Bunny\Message $message, Channel $channel, Client $client) use ($channel) {
//        var_dump(1);
//
//    }, 'fanout');
//
//});
//
//function createWorker(callable $func) {
//    $pid = pcntl_fork();
//    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
//    if (!$sockets) {
//        throw new SocketCreateException();
//    }
//
//    if ($pid == 0) {
//        fclose($sockets[0]);
//        $loop = \React\EventLoop\Factory::create();
//        $stream = new \Lan\Speed\Stream($sockets[1], $loop);
//
//        while (true) {
//            try {
//                $loop->addTimer(60, function ($timer) use ($loop) {
//                    $loop->cancelTimer($timer);
//                    $loop->stop();
//                });
//
//                $loop->run();
//            } catch (\Exception $ex) {
//                break;
//            }
//
//            if (!$stream->isWritable() || !$stream->isReadable()) {
//                break;
//            }
//
//        }
//    } else if ($pid > 0) {
//         fclose($sockets[1]);
//         return $sockets[0];
//    } else {
//
//    }
//}
//
//while(true) {
//    $eventLoop->addTimer(0.5, function ($timer) use ($eventLoop) {
//        $eventLoop->cancelTimer($timer);
//        $eventLoop->stop();
//    });
//
//    $eventLoop->run();
//}
echo json_encode(
    [
        'sms_loan_success_institution_total_30' => 1,
        'sms_repay_success_institution_total_30' => 2,
        'app_list_level_one_num' => 10,
        'sms_last_overdue_after_loan_repay_unexpired_total' => 2,
        'contact_52_start_count' => 2,
        'app_list_level_one_proportion' => 0.22,
        'contact_friend_social_proportion' => 0.2,
        'app_list_install_time_over_7_day_num' => 2,
    ]
).PHP_EOL;
$taskKey = ftok(__FILE__, 't');
$monitorKey = ftok(__FILE__, 'm');

$taskQueue = \msg_get_queue($taskKey);
$monitorQueue = \msg_get_queue($monitorKey);
var_dump(msg_stat_queue($taskQueue));;

class CacheQueue {
    const DESIRED_MSG_TYPE_ALL = 0;
    const DESIRED_MSG_TYPE_TASK = 1;
    const DESIRED_MSG_TYPE_MONITOR = 2;

    private $key;
    private $stopDispatchNum;
    private $blockTime = 1;
    private $queue;
    private $maxSize = 65535;
    private $isSerialize = true;
    private $pushIsBlock = false;
    private $flag = MSG_IPC_NOWAIT;

    public function __construct($filename, $id) {
        $this->key = ftok($filename, $id);
        $this->queue = msg_get_queue($this->key);
    }

    public function __destruct()
    {
        msg_remove_queue($this->queue);
    }

    public function pop($desiredType = self::DESIRED_MSG_TYPE_ALL) {
        msg_receive($this->queue,
            $desiredType,
            $msgType,
            $this->maxSize,
            $message,
            $this->isSerialize,
            $this->flag,
            $errCode
        );

        if ($errCode) {
            throw new \Exception('receive messsage failed');
        }
        return $message;
    }

    public function push($message, $msgType) {
        msg_send($this->queue, $msgType, $message, $this->isSerialize, $this->pushIsBlock, $errorCode);
        if ($errorCode) {
            throw new \Exception('send message failed');
        }
        return true;
    }


    public function getQueueCount() {
        $arr = msg_stat_queue($this->queue);
        return isset($arr['msg_qnum']) ? $arr['msg_qnum'] : 0;
    }
}


class Worker extends \Evenement\EventEmitter {
    private $name;
    private $monitor;
    private $task;
}

class master {
    private $cacheQueue = null;
    const MSG_TYPE_TASK = 1;
    const MSG_TYPE_MONITOR = 2;

    public function __construct() {
        $this->cacheQueue = new CacheQueue(__FILE__, 'p');
    }

    public function dispatchMessage($message, $type = self::MSG_TYPE_TASK) {
        try {
            $this->cacheQueue->push($message, $type);
        } catch (\Exception $ex) {
            var_dump($ex->getMessage());
        }
    }

    public function tryDispatchTask($message) {
        $this->dispatchMessage($message);
    }

    public function tryDispatchMonitor($message) {
        $this->dispatchMessage($message, self::MSG_TYPE_MONITOR);
    }
    public function handleMessage() {

    }
}