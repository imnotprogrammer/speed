<?php


namespace Lan\Speed\Impl;


use React\Stream\DuplexStreamInterface;

interface ProtocolInterface
{
    public static function input($buffer, DuplexStreamInterface $stream);
    public static function decode($buffer);
    public static function encode($buffer);
}