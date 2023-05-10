<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/27 00:51:30
 */

namespace Weline\Cron\Console\Cron\Task;

use Cron\CronExpression;
use Weline\Cron\Helper\CronStatus;
use Weline\Cron\Model\CronTask;
use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;

class Run implements CommandInterface
{
    /**
     * @var \Weline\Cron\Model\CronTask
     */
    private CronTask $cronTask;

    public function __construct(
        CronTask $cronTask
    )
    {
        $this->cronTask = $cronTask;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        array_shift($args);
        $force      = array_search('-f', $args);
        $task_names = $args;
        if (!is_bool($force)) {
            unset($task_names[$force]);
            # 解锁任务
            $this->cronTask->where($this->cronTask::fields_NAME, $task_names)->update(['status' => CronStatus::PENDING->value])->fetch();
            if ($task_names) {
                $this->cronTask->where($this->cronTask::fields_NAME, $task_names);
            }
            $this->cronTask->update(['status' => CronStatus::PENDING->value])->fetch();
        }
        # 读取给定的任务
        if ($task_names) {
            $this->cronTask->where($this->cronTask::fields_NAME, $task_names);
        }
        $tasks = $this->cronTask->select()->fetch()->getItems();
        /**@var CronTask $taskModel */
        foreach ($tasks as $taskModel) {
            $task_start_time = microtime(true);
            $task_run_date   = date('Y-m-d H:i:s');
            # 上锁
            $cron = CronExpression::factory($taskModel->getData('cron_time'));
            if ($cron->isDue($task_run_date)) {
                if ($taskModel->getData($taskModel::fields_STATUS) !== CronStatus::BLOCK->value) {
                    # 设置程序运行数据
                    # 上锁
                    $taskModel->setData($taskModel::fields_STATUS, CronStatus::BLOCK->value);
                    $taskModel->setData($taskModel::fields_RUN_TIME, $task_start_time);
                    $taskModel->setData($taskModel::fields_RUN_DATE, $task_run_date);
                    $taskModel->save(true);
                    /**@var \Weline\Cron\CronTaskInterface $task */
                    $task = ObjectManager::getInstance($taskModel->getData('class'));
                    $task->execute();
                    $task_end_time = microtime(true) - $task_start_time;

                    # 设置程序运行数据
                    $taskModel->setData($taskModel::fields_BLOCK_TIME, 0);
                    # 解锁
                    $taskModel->setData($taskModel::fields_STATUS, CronStatus::SUCCESS->value);
                    $taskModel->setData($taskModel::fields_RUNTIME, $task_end_time);
                    $taskModel->setData($taskModel::fields_RUN_TIMES, (int)$taskModel->getData($taskModel::fields_RUN_TIMES) + 1);
                } else {
                    # 设置程序运行数据
                    if ($run_time = $taskModel->getData($taskModel::fields_RUN_TIME)) {
                        $taskModel->setData(
                            $taskModel::fields_BLOCK_TIME,
                            $task_start_time - $run_time
                        );
                        if ($block_time = $taskModel->getData($taskModel::fields_BLOCK_TIME)) {
                            if ($block_time > ($taskModel->getData($taskModel::fields_BLOCK_UNLOCK_TIMEOUT) * 60)) {
                                $taskModel->setData($taskModel::fields_BLOCK_TIMES, (int)$taskModel->getData($taskModel::fields_BLOCK_TIMES) + 1);
                                $taskModel->setData($taskModel::fields_STATUS, CronStatus::PENDING->value);
                                $taskModel->setData($taskModel::fields_RUNTIME_ERROR_DATE, date('Y-m-d H:i:s'));
                                $taskModel->setData($taskModel::fields_RUNTIME_ERROR, '任务调度系统：调度任务阻塞超时自动解锁，请查看任务调度设置是否合理！');
                            }
                        }
                    }
                }
            } else {
                $taskModel->setData($taskModel::fields_STATUS, CronStatus::PENDING->value);
            }
            # 设置程序运行数据
            $taskModel->setData($taskModel::fields_NEXT_RUN_DATE, $cron->getNextRunDate()->format('Y-m-d H:i:s'));
            $taskModel->setData($taskModel::fields_MAX_NEXT_RUN_DATE, $cron->getNextRunDate('now', 3)->format('Y-m-d H:i:s'));
            $taskModel->setData($taskModel::fields_PRE_RUN_DATE, $cron->getPreviousRunDate()->format('Y-m-d H:i:s'));
            $taskModel->save(true);
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '运行计划调度任务。需要运行特定任务时：php bin/m cron:task:run demo demo_run 依次往后添加多个任务名 -f 选项强制解锁运行。';
    }
}
