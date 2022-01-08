<?php


namespace Lan\Speed\Impl;


interface MessageInterface
{
    public function getBody();

    public function getAction();

    public function toArray();

    public function setFromPID($pid);

    public function getFromPID();
}