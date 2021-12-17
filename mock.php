<?php


class mock
{
   private $name = 12;

   public function setName($name) {
       $this->name = $name;
   }

   public function getName() {
       return $this->name;
   }
}

class test {
    private $mock = null;

    public function __construct(mock $mock) {
        $this->mock = $mock;
    }

    public function updateName($name) {
        $this->mock->setName($name);
    }
    public function getName() {
        return $this->mock->getName();
    }

    public function getMock() {
        return $this->mock;
    }
}
$mock = new mock();

$test = new test($mock);
$mock->setName('123123');

var_dump($test->getName());
$newMock = $test->getMock();

$newMock->setName('newMock');

var_dump($test->getName());
unset($newMock);
var_dump($test->getName());

$list = new SplDoublyLinkedList();

$list->push($mock);
var_dump($list->count());
var_dump($list->offsetGet(0));
$list->shift();
var_dump($list->count());
var_dump($list->offsetGet(0));
