<?php


namespace Lan\Speed;


use Lan\Speed\Protocols\Simple;

class Stream extends \React\Stream\Stream
{
    /**
     * @var bool 是否读到流的结尾处
     */
    private $checkEOF = false;

    /**
     * @var string 接受到的数据buffer
     */
    private $receiveData = '';

    /**
     * @var bool 是否暂停接受
     */
    private $isPause = false;

    /**
     * @var string 使用协议类名
     */
    private $protocol = Simple::class;

    /**
     * @var int 数据包的长度
     */
    private $currentPackageLength = 0;

    /**
     * 关闭数据流
     */
    public function close()
    {
        if (!$this->writable && !$this->closing) {
            return;
        }

        $this->closing = false;

        $this->readable = false;
        $this->writable = false;

        $this->emit('end', array($this));
        $this->emit('close', array($this));

        $this->loop->removeReadStream($this->stream);
        $this->loop->removeWriteStream($this->stream);

        $this->buffer->removeAllListeners();
        $this->removeAllListeners();

        $this->handleClose();
    }

    /**
     * 读取数据，这里按照具体的协议分割数据流，然后得到我们的消息包
     *  这里参照了workerman中读取数据方法
     * @param $data
     * @param Stream $stream
     */
    public function baseRead($data, Stream $stream) {

        if ($data === '' || $data === false) {
            if ($this->checkEOF && (feof($this->stream) || !is_resource($this->stream) || $data === false)) {
                $this->end();
                return;
            }
        } else {
            $this->receiveData .= $data;
        }

        // If the application layer protocol has been set up.
        if ($this->protocol) {
            $parser = $this->protocol;
            while ($this->receiveData !== '') {
                // The current packet length is known.
                if ($this->currentPackageLength) {
                    // Data is not enough for a package.
                    if ($this->currentPackageLength > strlen($this->receiveData)) {
                        break;
                    }
                } else {
                    // Get current package length.
                    $this->currentPackageLength = $parser::length($this->receiveData, $this);
                    // The packet length is unknown.
                    if ($this->currentPackageLength === 0) {
                        break;
                    } elseif ($this->currentPackageLength > 0 && $this->currentPackageLength <= $this->bufferSize) {
                        // Data is not enough for a package.
                        if ($this->currentPackageLength > strlen($this->receiveData)) {
                            break;
                        }
                    } else { // Wrong package.
                        $this->emit('error', array(
                            new \RuntimeException('error package. package_length=' . var_export($this->currentPackageLength, true)), $this)
                        );
                        $this->end();
                        return;
                    }
                }

                // The current packet length is equal to the length of the buffer.
                if (strlen($this->receiveData) === $this->currentPackageLength) {
                    $buffer = $this->receiveData;
                    $this->receiveData  = '';
                } else {
                    // Get a full package from the buffer.
                    $buffer = substr($this->receiveData, 0, $this->currentPackageLength);
                    // Remove the current package from the receive buffer.
                    $this->receiveData = substr($this->receiveData, $this->currentPackageLength);
                }
                // Reset the current packet length to 0.
                $this->currentPackageLength = 0;
                $this->emitMessage($parser::decode($buffer, $this));
            }
            return;
        }

        if ($this->receiveData === '' || $this->isPause) {
            return;
        }

        $this->emitMessage($this->receiveData);
        $this->receiveData = '';
    }

    public function emitMessage($message) {

        try {
            $this->emit('message', array($message, $this));
        } catch (\Exception $e) {
            $this->emit('error', array($e, $this));
        }
    }

    /**
     * 发送数据
     * @param $buffer
     * @return bool|void|null
     */
    public function send($buffer) {
        if ($this->protocol) {
            $parser = $this->protocol;
            $buffer = $parser::encode($buffer, $this);
            if (!$buffer) {
                return null;
            }

            return $this->write($buffer);
        }
        return $this->write($buffer);
    }
}