<?php


namespace Lan\Speed;


use Lan\Speed\Protocols\Simple;

class Stream extends \React\Stream\Stream
{
    private $checkEOF = false;

    private $receiveData = '';

    private $isPause = false;

    private $protocol = Simple::class;

    private $currentPackageLength = 0;

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
            while ($this->receiveData !== '' && !$this->isPause) {
                // The current packet length is known.
                if ($this->currentPackageLength) {
                    // Data is not enough for a package.
                    if ($this->currentPackageLength > strlen($this->receiveData)) {
                        break;
                    }
                } else {
                    // Get current package length.
                    $this->currentPackageLength = $parser::input($this->receiveData, $this);
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


    public function baseWrite($buffer) {
        $parser = $this->protocol;
        return $this->write($parser::encode($buffer, $this));
    }
}