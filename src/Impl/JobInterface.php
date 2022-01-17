<?php


namespace Lan\Speed\Impl;


interface JobInterface
{
    /**
     * 任务是否可以执行
     * @return mixed
     */
    public function isRunAble();

    /**
     * 执行
     * @return mixed
     */
    public function run();

    /**
     * 获取任务超时时间
     * @return mixed
     */
    public function getTimeout();

    /**
     * 当job执行超时得时候，job是否继续执行
     * true： job超时了继续执行，不终止job
     * false: job超时了不执行，退出job
     * @return bool
     */
    public function alwaysExecuteWhenTimeout();

    /**
     * 是否立即执行，
     * 当job执行完成后，如果job是超时，是否需要调度器立即派发任务给子进程执行
     * true: 立即派发
     * false: 等到下一个定时任务周期(默认为一分钟调度)执行
     * @return mixed
     */
    public function onceExecuteWhenTimeout();
}