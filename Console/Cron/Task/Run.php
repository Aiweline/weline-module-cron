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
use Weline\Cron\CronTaskInterface;
use Weline\Cron\Helper\CronStatus;
use Weline\Cron\Helper\Process;
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
    private Printing $printing;

    public function __construct(
        CronTask $cronTask,
        Printing $printing
    )
    {
        $this->cronTask = $cronTask;
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        array_shift($args);
        $force   = is_int(array_search('-f', $args)) || is_int(array_search('-force', $args));
        $process = is_int(array_search('-p', $args)) || is_int(array_search('-process', $args));
        foreach ($args as $key => $arg) {
            if ($arg == '-f' || $arg == '-force' || $arg == '-p' || $arg == '-process') {
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
        # 如果给定的任务是单个任务，说明是具体要执行的任务
        if (($process || $force) && count($task_names) == 1) {
            /**@var CronTask $task */
            $task = $this->cronTask->where($this->cronTask::fields_EXECUTE_NAME, array_shift($task_names))->find()->fetch();
            if (!$task->getId()) {
                ObjectManager::getInstance(Printing::class)->error(__('请指执行的任务不存在！'));
                exit;
            }
            $class = $task->getData(CronTask::fields_CLASS);
            /**@var CronTaskInterface $instance */
            $instance = ObjectManager::getInstance($class);
            $instance->execute();
            $task->setData($task::fields_RUN_TIMES, (int)$task->getData($task::fields_RUN_TIMES) + 1);
            # 设置程序运行数据
            $task->setData($task::fields_BLOCK_TIME, 0);
            # 解锁
            $task->setData($task::fields_STATUS, CronStatus::SUCCESS->value);
            $task_end_time = microtime(true) - ($task->getData(CronTask::fields_RUN_TIME));
            $task->setData($task::fields_RUNTIME, $task_end_time);
            # 运行完毕将进程ID设置为0
            $task->setData($task::fields_PID, 0);
            exit;
        }
        # 读取给定的任务
        if ($task_names) {
            foreach ($task_names as $taskName) {
                $this->cronTask->where($this->cronTask::fields_EXECUTE_NAME, $taskName);
            }
        }
        $pageSize = 1;
        $this->cronTask->pagination(1, $pageSize)->select()->fetch();
        # 读取给定的任务
        if ($task_names) {
            foreach ($task_names as $taskName) {
                $this->cronTask->where($this->cronTask::fields_EXECUTE_NAME, $taskName);
            }
        }
        # 分页读取任务
        $taskTotal = (int)$this->cronTask->pagination['totalSize'];
        $taskPages = (int)$this->cronTask->pagination['lastPage'];
        /**@var CronTask $taskModel */
        $descriptorspec = array(
            0 => array('pipe', 'r'),   // 子进程将从此管道读取stdin
            1 => array('pipe', 'w'),   // 子进程将向此管道写入stdout
            2 => array('pipe', 'w')    // 子进程将向此管道写入stderr
        );
        foreach (range(1, $taskPages) as $current_page) {
            $offset       = ($current_page - 1) * $pageSize;
            $currentTotal = $offset + $pageSize;
            echo PHP_EOL;
            CronStatus::displayProgressBar(__('任务进度：页(%1=>%2)/目(%3/%4)', [$taskPages, $current_page, $taskTotal, $currentTotal]), $currentTotal,
                $taskTotal, false);
            $tasks = $this->cronTask->limit($pageSize, $offset)
                ->select()
                ->fetch()
                ->getItems();
            # 进程信息管理
            $processes = [];
            $pipes     = [];
            foreach ($tasks as $key => $taskModel) {
                $execute_name    = $taskModel->getData($taskModel::fields_EXECUTE_NAME);
                $task_start_time = microtime(true);
                $task_run_date   = date('Y-m-d H:i:s');
                $run_time        = $taskModel->getData($taskModel::fields_RUN_TIME) ?? 0;
                # 上锁
                $cron = CronExpression::factory($taskModel->getData('cron_time'));
                # 设置程序预计数据
                $taskModel->setData($taskModel::fields_NEXT_RUN_DATE, $cron->getNextRunDate()->format('Y-m-d H:i:s'));
                $taskModel->setData($taskModel::fields_MAX_NEXT_RUN_DATE, $cron->getNextRunDate('now', 3)->format('Y-m-d H:i:s'));
                $taskModel->setData($taskModel::fields_PRE_RUN_DATE, $cron->getPreviousRunDate()->format('Y-m-d H:i:s'));
                $pid = $taskModel->getData($taskModel::fields_PID) ?: 0;
                if ($pid) {
                    if (Process::isProcessRunning($pid)) {
                        # 如果超时
                        if ($force) {
                            $msg    = __('%1 程序ID:%2 正在运行中，当前强制执行正在杀死进程中...', [$execute_name, $pid]);
                            $output = Process::getProcessOutput($execute_name);
                            Process::unsetLogProcessFilePath($execute_name);
                            $taskModel->setData($taskModel::fields_RUNTIME_ERROR, $msg)
                                ->setData($taskModel::fields_RUNTIME_ERROR, $output)
                                ->save();
                            d($msg);
                            Process::killPid($pid);
                            if (Process::isProcessRunning($pid)) {
                                $force = false;
                                $msg   = __('%1 程序ID:%2 杀死失败！程序不会强制执行，请手动杀死进程后重试!', [$execute_name, $pid]);
                                $taskModel->setData($taskModel::fields_RUNTIME_ERROR, $msg)->save();
                                d($msg);
                            }
                        } else {
                            $msg = __('%1 程序ID:%2 正在运行中，若要强制执行，请手动杀死进程后重试!或者使用配置项’-f‘的强制执行', [$execute_name, $pid]);
                            $taskModel->setData($taskModel::fields_RUNTIME_ERROR, $msg)->save();
                            d($msg);
                            continue;
                        }
                    } else {
                        $msg = __('%1 程序ID:%2 已运行完毕!', [$execute_name, $pid]);
                        $pid = 0;
                        $taskModel->setData($taskModel::fields_RUN_TIMES, (int)$taskModel->getData($taskModel::fields_RUN_TIMES) + 1);
                        # 设置程序运行数据
                        $taskModel->setData($taskModel::fields_BLOCK_TIME, 0);
                        $output = Process::getProcessOutput($execute_name);
                        Process::unsetLogProcessFilePath($execute_name);
                        $taskModel->setData($taskModel::fields_RUNTIME_ERROR, $output);
                        # 解锁
                        $taskModel->setData($taskModel::fields_STATUS, CronStatus::SUCCESS->value);
                        $taskModel->setData($taskModel::fields_RUNTIME, microtime(true) - $task_start_time);
                        # 运行完毕将进程ID设置为0
                        $taskModel->setData($taskModel::fields_PID, 0)
                            ->setData($taskModel::fields_RUNTIME_ERROR, $output)
                            ->save();
                        d($msg);
                        continue;
                    }
                }
                d($taskModel->getData($taskModel::fields_NAME) . ' ' . $execute_name);
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
                        $process_log_path = Process::getLogProcessFilePath($task->execute_name());
                        $command_fix      = !IS_WIN ? ' 2>&1 & echo $!' : '';
                        $command          = 'cd ' . BP . ' && nohup ' . PHP_BINARY . ' bin/m cron:task:run -process ' . $task->execute_name() . ($force ? ' -force' : '') . ' > ' . $process_log_path . $command_fix;
                        $process          = proc_open($command, $descriptorspec, $procPipes);
                        # 进程保存到进程数组
                        $processes[$key] = $process;
                        # 设置进程非阻塞
                        stream_set_blocking($procPipes[1], false);
                        $pipes[$key] = $procPipes;
                        if (is_resource($process)) {
                            $pid = proc_get_status($process)['pid'] + 2;
                            # 记录PID
                            $taskModel->setData($taskModel::fields_PID, $pid)
                                ->save();
                            // 关闭文件指针
                            fclose($procPipes[0]);
                            fclose($procPipes[1]);
                            fclose($procPipes[2]);
                        } else {
                            $taskModel->setData($taskModel::fields_RUNTIME_ERROR, __('进程创建失败！请检查进程状态！'))
                                ->save();
                        }
                    } else {
                        # 到了程序下次运行的时间，但是程序仍然处于block阻塞状态，设置程序运行阻塞数据
                        if ($run_time) {
                            $taskModel->setData($taskModel::fields_BLOCK_TIME, $task_start_time - $run_time);
                        }
                        if ($block_time = $taskModel->getData($taskModel::fields_BLOCK_TIME)) {
                            if ($block_time > ($taskModel->getData($taskModel::fields_BLOCK_UNLOCK_TIMEOUT) * 60)) {
                                $taskModel->setData($taskModel::fields_BLOCK_TIMES, (int)$taskModel->getData($taskModel::fields_BLOCK_TIMES) + 1);
                                $taskModel->setData($taskModel::fields_STATUS, CronStatus::PENDING->value);
                                $taskModel->setData($taskModel::fields_RUNTIME_ERROR_DATE, date('Y-m-d H:i:s'));
                                $taskModel->setData($taskModel::fields_RUNTIME_ERROR, '任务调度系统：调度任务阻塞超时自动解锁，请查看任务调度设置是否合理！');
                            }
                        }
                    }
                } else {
                    $taskModel->setData($taskModel::fields_STATUS, CronStatus::PENDING->value);
                }
                # 如果有进程PID，检测是否运行结束
                if ($pid !== 0) {
                    $task_end_time = microtime(true) - $task_start_time;
                    $isRunning     = Process::isProcessRunning($pid);
                    if ($isRunning) {
                        $output = Process::getProcessOutput($execute_name);
                        $taskModel->setData($taskModel::fields_BLOCK_TIME, $task_start_time - $run_time)
                            ->setData($taskModel::fields_RUNTIME_ERROR, $output)
                            ->save();
                        echo $output;
                        continue;
                    } elseif ($taskModel->getData($taskModel::fields_STATUS) !== CronStatus::SUCCESS->value) {
                        $taskModel->setData($taskModel::fields_RUN_TIMES, (int)$taskModel->getData($taskModel::fields_RUN_TIMES) + 1);
                        # 设置程序运行数据
                        $taskModel->setData($taskModel::fields_BLOCK_TIME, 0);
                        # 解锁
                        $taskModel->setData($taskModel::fields_STATUS, CronStatus::SUCCESS->value);
                        $taskModel->setData($taskModel::fields_RUNTIME, $task_end_time);
                        # 运行完毕将进程ID设置为0
                        $output = Process::getProcessOutput($execute_name);
                        $taskModel->setData($taskModel::fields_PID, 0)
                            ->setData($taskModel::fields_RUNTIME_ERROR, $output);
                        echo $output;
                    }
                }
                # 保存未命执行的任务数据
                $taskModel->save();
            }

//            # 循环检查各进程，直到所有子进程结束
//            while (array_filter($processes, function ($proc) { return proc_get_status($proc)['running']; })) {
//                foreach ($tasks as $i => $task) {
//                    # 如果有对应进程,读取所有可读取的输出（缓冲未读输出）
//                    if (!empty($pipes[$i])) {
//                        $str = fread($pipes[$i][1], 1024);
//                        if ($str) {
//                            echo $str;
//                        }
//                    }
//                }
//            }
//            # 关闭所有管道和进程
//            foreach ($tasks as $i => $task) {
//                if (!empty($pipes[$i])) {
//                    fclose($pipes[$i][1]);
//                    proc_close($processes[$i]);
//                    $task->setData($task::fields_RUN_TIMES, (int)$task->getData($task::fields_RUN_TIMES) + 1);
//                    # 设置程序运行数据
//                    $task->setData($task::fields_BLOCK_TIME, 0);
//                    # 解锁
//                    $task->setData($task::fields_STATUS, CronStatus::SUCCESS->value);
//                    $task_end_time = microtime(true) - $task_start_time;
//                    $task->setData($task::fields_RUNTIME, $task_end_time);
//                    # 运行完毕将进程ID设置为0
//                    $task->setData($task::fields_PID, 0);
//                    $task->save();
//                }
//            }
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
