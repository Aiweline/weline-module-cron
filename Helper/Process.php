<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：22/12/2023 09:26:37
 */

namespace Weline\Cron\Helper;

class Process
{

    static public function killPid(int $pid)
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            exec("kill $pid 2>/dev/null", $output, $exitCode);
            return $exitCode === 0;
        } else {
            exec("taskkill /F /PID $pid 2>NUL", $output, $exitCode);
            return $exitCode === 0;
        }
    }

    static public function isProcessRunning(int $pid)
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $output = [];
            exec("tasklist /FI \"PID eq $pid\" 2>NUL", $output, $exitCode);
            foreach ($output as $line) {
                if (strpos($line, " $pid ") !== false) {
                    return true;
                }
            }
        } else {
            $output = [];
            try {
                exec("ps -p $pid", $output, $exitCode);
            } catch (\Throwable $e) {
                return false;
            }
            return count($output) > 1;
        }
        return false;
    }

    static public function getProcessOutput(int $pid): string|false
    {
        $output = false;
        if (!self::isProcessRunning($pid)) {
            return $output;
        }
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows 上的实现
            $command = "wmic process get ProcessId,CommandLine | findstr $pid";
            $output  = shell_exec($command);
        } else {
            // Linux 上的实现
            if (file_exists("/proc/$pid")) {
                $descriptors = [
                    1 => ['pipe', 'w'],
                ];
                $process = proc_open("cat /proc/$pid/fd/1", $descriptors, $pipes);
                if (is_resource($process)) {
                    $output = stream_get_contents($pipes[1]);
                    fclose($pipes[1]);
                    proc_close($process);
                    return $output;
                }
            }
        }
        return $output;
    }
}