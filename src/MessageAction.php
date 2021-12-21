<?php


namespace Lan\Speed;

/**
 * Class MessageAction
 * @package Lan\Speed
 * 退出发送消息流程
 * 子进程  主进程
 * MESSAGE_READY_EXIT -->
 *                    <--  MESSAGE_LAST
 * MESSAGE_READIED_EXIT -->
 *                    <-- MESSAGE_PLEASE_SHUTDOWN
 * ......
 */
final class MessageAction
{
    /** @var int 自定义消息，主要方便后期扩展使用 */
    const MESSAGE_CUSTOM = 1;

    /** @var int 子进程退出消息，主要由子进程发送给父进程 */
    const MESSAGE_READY_EXIT = 2;

    /** @var int 队列消息，主要由主进程发送给子进程 */
    const MESSAGE_CONSUME = 3;

    /** @var int 消息处理完毕， 主要由子进程发送给主进程 */
    const MESSAGE_FINISHED = 4;

    /** @var int 不再接受消费消息(子进程准备退出), 主进程发送给子进程 */
    const MESSAGE_LAST = 5;

    /** @var int 子进程告诉父进程，开始清除与我有关的消息 */
    const MESSAGE_QUIT_ME = 6;

    // 父进程发送给子进程
    const MESSAGE_YOU_EXIT = 7;

}