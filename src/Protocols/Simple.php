<?php


namespace Lan\Speed\Protocols;


use Lan\Speed\Impl\ProtocolInterface;
use React\Stream\DuplexStreamInterface;

class Simple implements ProtocolInterface
{

    /**
     * Check the integrity of the package.
     *
     * @param string        $buffer
     * @param DuplexStreamInterface $stream
     * @return int
     */
    public static function input($buffer, DuplexStreamInterface $stream)
    {
        if (strlen($buffer) < 4) {
            return 0;
        }
        $unpack_data = unpack('Ntotal_length', $buffer);
        return $unpack_data['total_length'];
    }

    /**
     * Decode.
     *
     * @param string $buffer
     * @return string
     */
    public static function decode($buffer)
    {
        return substr($buffer, 4);
    }

    /**
     * Encode.
     *
     * @param string $buffer
     * @return string
     */
    public static function encode($buffer)
    {
        $total_length = 4 + strlen($buffer);
        return pack('N', $total_length) . $buffer;
    }
}