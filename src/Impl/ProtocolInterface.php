<?php


namespace Lan\Speed\Impl;


use React\Stream\DuplexStreamInterface;

interface ProtocolInterface
{
    /**
     * 获取包的长度
     * @param $buffer
     * @param DuplexStreamInterface $stream
     * @return mixed
     */
    public static function length($buffer, DuplexStreamInterface $stream);

    /**
     * 协议解析
     * @param $buffer
     * @return mixed
     */
    public static function decode($buffer);

    /**
     * 转变协议
     * @param $buffer
     * @return mixed
     */
    public static function encode($buffer);
}