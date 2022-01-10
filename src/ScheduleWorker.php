<?php


namespace Lan\Speed;

class ScheduleWorker
{
    /** @var int 分配尝试次数 */
    const TRY_ALLOCATE_NUMS = 2;

    /** @var int 进程当前状态-空闲 */
    const WORKER_STATE_FREE = 0;

    /** @var int 进程当前状态-忙碌 */
    const WORKER_STATE_BUSY = 1;

    /** @var int 进程当前状态-不在处理消息 */
    const WORKER_STATE_IGNORE = 3;

    /** @var int 进程不存在 */
    const WORKER_STATE_NOT_EXIST = -1;

    /** @var array 进程调度表
     * 格式:  array(
     *           123 => 0 //  当前进程状态 0-空闲 1-忙碌 2-已分配，3-不再处理消息
     *           ...
     *       )
     */
    private $workerInfo = array();

    /** @var int 空闲进程数 */
    private $freeCount = 0;


    /**
     * 获取可以分配的空闲进程ID
     * @return int|string 进程PID
     */
    public function allocate() {
        for ($i = 0; $i < self::TRY_ALLOCATE_NUMS; $i++) {
            foreach ($this->workerInfo as $pid => $state) {
                if ($state == self::WORKER_STATE_FREE) {
                    return $pid;
                }
            }
        }

        return 0;
    }

    /** 是否有可用的工作(子)进程 */
    public function hasAvailableWorker() {
        return $this->getFreeWorker() > 0;
    }

    /**
     * 更改进程状态
     * @param $workerPID
     * @param $status
     */
    public function updateWorker($workerPID, $status) {
        $currentState = isset($this->workerInfo[$workerPID]) ?
            $this->workerInfo[$workerPID] : self::WORKER_STATE_NOT_EXIST;

        switch ($status) {
            case self::WORKER_STATE_FREE:
                if ($currentState == self::WORKER_STATE_BUSY || $currentState == self::WORKER_STATE_NOT_EXIST) {
                    $this->freeCount++;
                }
                break;
            case self::WORKER_STATE_IGNORE:
            case self::WORKER_STATE_BUSY:
                if ($currentState == self::WORKER_STATE_FREE) {
                    $this->freeCount--;
                }
                break;
            default: break;
        }

        $this->workerInfo[$workerPID] = $status;
    }

    /**
     * 将进程状态变为空闲状态
     * @param $workerPID
     */
    public function workerFree($workerPID) {
        $this->updateWorker($workerPID, self::WORKER_STATE_FREE);
    }

    /**
     * 将进程变为工作状态
     * @param $workerPID
     */
    public function workerBusy($workerPID) {
        $this->updateWorker($workerPID, self::WORKER_STATE_BUSY);
    }

    /**
     * 将工作进程进行退休
     * @param $workerPid
     */
    public function retireWorker($workerPid) {
        $this->updateWorker($workerPid, self::WORKER_STATE_IGNORE);
    }

    public function getFreeWorker() {
        return $this->freeCount;
    }

    public function getWorkerState($pid) {
        return isset($this->workerInfo[$pid]) ? $this->workerInfo[$pid] : '-1';
    }
    
    public function getRetireWorker() {
        $pids = array();
        foreach ($this->workerInfo as $pid => $state) {
            if ($state == self::WORKER_STATE_IGNORE) {
                $pids[] = $pid;
            }
        }
        return $pids;
    }

    /**
     * 清除退休/已经不存在的进程状态信息，
     * 主要在进程空闲退出时，即使回收无用信息，避免内存溢出
     */
    public function clear() {
        foreach ($this->workerInfo as $pid => $state) {
            if ($state == self::WORKER_STATE_IGNORE) {
                unset($this->workerInfo[$pid]);
            }
        }
    }
}