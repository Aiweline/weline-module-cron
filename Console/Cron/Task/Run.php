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

use Aiweline\KteInventory\Model\SiteCronTask;
use Cron\CronExpression;
use Weline\Cron\Helper\CronStatus;
use Weline\Cron\Model\CronTask;
use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;


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
        $force = is_int(array_search('-f', $args));
        foreach ($args as $key => $arg) {
            if ($arg == '-f') {
                unset($args[$key]);
            }
        }
        $task_names = $args;
        if (!is_bool($force)) {
            # 解锁任务
            if (empty($task_names)) {
                ObjectManager::getInstance(Printing::class)->error(__('请指定要执行的任务！php bin/m cron:task:run demo -f'));
                die;
            }
        }
        # 读取给定的任务
        if ($task_names) {
            foreach ($task_names as $taskName) {
                $this->cronTask->where($this->cronTask::fields_EXECUTE_NAME, $taskName);
            }
        }

        $tasks = $this->cronTask->select()->fetch()->getItems();
        /**@var CronTask $taskModel */
        $descriptorspec = array(
            0 => array('pipe', 'r'),   // 子进程将从此管道读取stdin
            1 => array('pipe', 'w'),   // 子进程将向此管道写入stdout
            2 => array('pipe', 'w')    // 子进程将向此管道写入stderr
        );
        foreach ($tasks as $taskModel) {
            $task_start_time = microtime(true);
            $task_run_date   = date('Y-m-d H:i:s');
            # 上锁
            $cron = CronExpression::factory($taskModel->getData('cron_time'));
            if ($force || $cron->isDue($task_run_date)) {
                if ($force || $taskModel->getData($taskModel::fields_STATUS) !== CronStatus::BLOCK->value) {
                    # 设置程序运行数据
                    # 上锁
                    $taskModel->setData($taskModel::fields_STATUS, CronStatus::BLOCK->value);
                    $taskModel->setData($taskModel::fields_RUN_TIME, $task_start_time);
                    $taskModel->setData($taskModel::fields_RUN_DATE, $task_run_date);
                    $taskModel->save(true);
                    /**@var \Weline\Cron\CronTaskInterface $task */
                    $task = ObjectManager::getInstance($taskModel->getData('class'));
                    # 创建异步程序
                    $command = 'cd ' . BP . ' && ' . PHP_BINARY . ' bin/m cron:task:run ' . $task->execute_name();
                    $process = proc_open($command, $descriptorspec, $pipes);
                    if (is_resource($process)) {
                        $status = proc_get_status($process);
                        $pid    = $status['pid'];
                        # 记录PID
                        $taskModel->setData($taskModel::fields_PID, $pid)
                                  ->save();
                        // 命令已成功作为异步进程执行
                        // 关闭不需要的管道
                        fclose($pipes[0]);
                        // 读取子进程的输出（如果需要）
                        $output = stream_get_contents($pipes[1]);
                        // 关闭剩余的管道
                        fclose($pipes[1]);
                        fclose($pipes[2]);
                        // 等待进程结束（如果需要）
                        $exitCode = proc_close($process);
                        // 根据需要处理输出或退出码
                        // 继续执行其他任务或退出
                    }else{
                        $taskModel->setData($taskModel::fields_RUNTIME_ERROR, __('进程创建失败！请检查进程状态！'));
                    }
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
            # 如果有进程PID，检测是否运行结束
            if($pid=$taskModel->getData($taskModel::fields_PID)){
                $task_end_time = microtime(true) - $task_start_time;
                $isRunning = posix_kill($pid, 0);
                if ($isRunning) {
                    $taskModel->setData($taskModel::fields_BLOCK_TIME, $task_start_time - $run_time);
                } elseif($taskModel->getData($taskModel::fields_STATUS) !== CronStatus::SUCCESS->value){
                    $taskModel->setData($taskModel::fields_RUN_TIMES, (int)$taskModel->getData($taskModel::fields_RUN_TIMES) + 1);
                    # 设置程序运行数据
                    $taskModel->setData($taskModel::fields_BLOCK_TIME, 0);
                    # 解锁
                    $taskModel->setData($taskModel::fields_STATUS, CronStatus::SUCCESS->value);
                    $taskModel->setData($taskModel::fields_RUNTIME, $task_end_time);
                }
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
