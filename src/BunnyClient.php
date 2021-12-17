<?php


namespace Lan\Speed;


use Bunny\Async\Client;
use Bunny\ClientStateEnum;
use Bunny\Exception\ClientException;
use function React\Promise\all;
use function React\Promise\reject;

class BunnyClient extends Client
{
    /**
     * 重写disconnect方法原因是因为这个包的版本有bug，这里修复一下
     * @param int $replyCode
     * @param string $replyText
     * @return \React\Promise\Promise|\React\Promise\PromiseInterface|\React\Promise\RejectedPromise
     */
    public function disconnect($replyCode = 0, $replyText = "")
    {
        if ($this->state === ClientStateEnum::DISCONNECTING) {
            return $this->disconnectPromise;
        }

        if ($this->state !== ClientStateEnum::CONNECTED) {
            return reject(new ClientException("Client is not connected."));
        }

        $this->state = ClientStateEnum::DISCONNECTING;

        $promises = [];

        if ($replyCode === 0) {
            foreach ($this->channels as $channel) {
                $promises[] = $channel->close($replyCode, $replyText);
            }
        }

        if ($this->heartbeatTimer) {
            $this->eventLoop->cancelTimer($this->heartbeatTimer);
            $this->heartbeatTimer = null;
        }

        return $this->disconnectPromise = all($promises)->then(function () use ($replyCode, $replyText) {
            if (!empty($this->channels)) {
                throw new \LogicException("All channels have to be closed by now.");
            }

            return $this->connectionClose($replyCode, $replyText, 0, 0);
        })->then(function () {
            $this->eventLoop->removeReadStream($this->getStream());
            $this->closeStream();
            $this->init();
            return $this;
        });
    }
}