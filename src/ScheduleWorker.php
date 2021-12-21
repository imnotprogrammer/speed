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

    /** @var int 进程当前状态-已分配 */
    const WORKER_STATE_ALLOCATE = 2;

    /** @var int 进程当前状态-不在处理消息 */
    const WORKER_STATE_IGNORE = 3;

    /** @var int 进程不存在 */
    const WORKER_STATE_NOT_EXIST = -1;

    const WORKER_BUSY = 'busy';
    const WORKER_FREE = 'free';

    /** @var array 进程调度表
     * 格式:  array(
     *           123 => 0 //  当前进程状态 0-空闲 1-忙碌 2-已分配，3-不再处理消息
     *           ...
     *       )
     */
    private $workerInfo = array();

    private $freeCount = 0;

    private $busyCount = 0;

    /**
     * 获取可以分配的空闲进程ID
     * @return int|string 进程PID
     */
    public function allocate() {
        for ($i = 0; $i < self::TRY_ALLOCATE_NUMS; $i++) {
            foreach ($this->workerInfo as $pid => $state) {
                if ($state == self::WORKER_STATE_FREE) {
                    $this->workerAllocate($pid);
                    return $pid;
                }
            }
        }

        return 0;
    }

    public function hasAvailableWorker() {
        return $this->freeCount > 0;
    }

    /**
     * 更改进程状态
     * @param $workerPID
     * @param $status
     */
    public function updateWorker($workerPID, $status) {
        $this->workerInfo[$workerPID] = $status;
    }

    /**
     * 将进程状态变为空闲状态
     * @param $workerPID
     */
    public function workerFree($workerPID) {

        $currentStatus = $this->workerInfo[$workerPID];
        if ($currentStatus != self::WORKER_STATE_BUSY) {
            return;
        }

        $this->incr(self::WORKER_FREE);
        $this->updateWorker($workerPID, self::WORKER_STATE_FREE);
    }

    /**
     * 将进程变为工作状态
     * @param $workerPID
     */
    public function workerBusy($workerPID) {
        //$this->decr();
        $this->updateWorker($workerPID, self::WORKER_STATE_BUSY);
    }

    /**
     * 将进程指向已分配状态，不然该进程被多次分配
     * @param $workerPID
     */
    public function workerAllocate($workerPID) {
        $currentStatus = $this->workerInfo[$workerPID];
        if ($currentStatus != self::WORKER_STATE_FREE) {
            return;
        }

        $this->decr();
        $this->updateWorker($workerPID, self::WORKER_STATE_ALLOCATE);
    }

    /**
     * 销毁退出的或僵尸进程信息，避免被分配到任务
     * @param $workerPID
     */
    public function destroy($workerPID) {
        if (isset($this->workerInfo[$workerPID])) {
            $state = $this->workerInfo[$workerPID];
            if ($state == self::WORKER_STATE_FREE) {
                $this->decr();
            } else {
                $this->decr(self::WORKER_BUSY);
            }

            unset($this->workerInfo[$workerPID]);
        }

    }

    public function getWorkerInfo() {
        return $this->workerInfo;
    }

    public function incr($type = self::WORKER_FREE) {
        switch ($type) {
            case self::WORKER_BUSY:
                $this->busyCount++;
                break;
            case self::WORKER_FREE:
                $this->freeCount++;
                break;
            default:
                break;
        }
    }

    public function decr($type = self::WORKER_FREE) {
        switch ($type) {
            case self::WORKER_BUSY:
                $this->busyCount--;
                $this->busyCount = $this->busyCount >= 0 ? $this->busyCount : 0;
                break;
            case self::WORKER_FREE:
                $this->freeCount--;
                $this->freeCount = $this->freeCount >= 0 ? $this->freeCount : 0;
                break;
            default:
                break;
        }
    }

    /**
     * 将工作进程进行退休
     * @param $workerPid
     */
    public function retireWorker($workerPid) {
        if (isset($this->workerInfo[$workerPid])) {
            $status = $this->workerInfo[$workerPid];
            if ($status == self::WORKER_STATE_BUSY) {
                $this->decr(self::WORKER_BUSY);
            } else if ($status == self::WORKER_STATE_FREE) {
                $this->decr();
            }
            $this->workerInfo[$workerPid] = self::WORKER_STATE_IGNORE;
        }
    }

    public function getFreeWorker() {
        return $this->freeCount;
    }

    public function getWorkerState($pid) {
        return isset($this->workerInfo[$pid]) ? $this->workerInfo[$pid] : '-1';
    }
}