<?php

namespace modmore\SiteDashClient\System;

use modmore\SiteDashClient\CommandInterface;

class Status implements CommandInterface {
    /**
     * @var \modX
     */
    private $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    public function run()
    {
        $return = [
            'success' => true,
            'load' => $this->getLoadAverages(),
            'memory' => $this->getMemoryUsage(),
            'db_alive' => $this->isDatabaseAlive(),
            'cpu_count' => $this->getCPUCount(),
        ];
        
        http_response_code($return['success'] ? 200 : 500);
        echo json_encode($return, JSON_PRETTY_PRINT);
    }

    private function getLoadAverages()
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if (is_array($load) && count($load) === 3) {
                return $load;
            }
        }

        return 'notavailable';
    }

    private function isDatabaseAlive()
    {
        $randomAliveString = 'SiteDashAliveCheck-' . rand(0, 9999);
        $alive = $this->modx->query('SELECT ' . $this->modx->quote($randomAliveString));

        return $alive && $randomAliveString === $alive->fetchColumn();
    }

    private function getCPUCount()
    {
        $numCpus = 1;

        if (is_file('/proc/cpuinfo') && is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor/m', $cpuinfo, $matches);
            return count($matches[0]);
        }

        if (stripos(PHP_OS, 'WIN') === 0) {
            $process = @popen('wmic cpu get NumberOfCores', 'rb');
            if ($process !== false) {
                fgets($process);
                $numCpus = (int)fgets($process);
                pclose($process);
                return $numCpus;
            }
        }

        $process = @popen('sysctl -a', 'rb');
        if ($process !== false) {
            $output = stream_get_contents($process);
            preg_match('/hw.ncpu: (\d+)/', $output, $matches);
            if ($matches) {
                $numCpus = (int)$matches[1][0];
            }
            pclose($process);
        }

        return $numCpus;
    }

    private function getMemoryUsage()
    {
        $memoryTotal = null;
        $memoryFree = null;

        if (false !== stripos(PHP_OS, 'win')) {
            // Get total physical memory (this is in bytes)
            $cmd = 'wmic ComputerSystem get TotalPhysicalMemory';
            @exec($cmd, $outputTotalPhysicalMemory);

            // Get free physical memory (this is in kibibytes!)
            $cmd = 'wmic OS get FreePhysicalMemory';
            @exec($cmd, $outputFreePhysicalMemory);

            if ($outputTotalPhysicalMemory && $outputFreePhysicalMemory) {
                // Find total value
                foreach ($outputTotalPhysicalMemory as $line) {
                    if ($line && preg_match('/^[\d]+$/', $line)) {
                        $memoryTotal = $line;
                        break;
                    }
                }

                // Find free value
                foreach ($outputFreePhysicalMemory as $line) {
                    if ($line && preg_match('/^[\d]+$/', $line)) {
                        $memoryFree = $line;
                        $memoryFree *= 1024;  // convert from kibibytes to bytes
                        break;
                    }
                }
            }
        }
        elseif (is_readable('/proc/meminfo')) {
            $stats = @file_get_contents('/proc/meminfo');

            if ($stats !== false) {
                // Separate lines
                $stats = str_replace(array("\r\n", "\n\r", "\r"), "\n", $stats);
                $stats = explode("\n", $stats);

                // Separate values and find correct lines for total and free mem
                foreach ($stats as $statLine) {
                    $statLineData = explode(':', trim($statLine));

                    // Total memory
                    if (count($statLineData) === 2 && trim($statLineData[0]) === 'MemTotal') {
                        $memoryTotal = trim($statLineData[1]);
                        $memoryTotal = explode(' ', $memoryTotal);
                        $memoryTotal = $memoryTotal[0];
                        $memoryTotal *= 1024;  // convert from kibibytes to bytes
                    }

                    // Free memory
                    if (count($statLineData) === 2 && trim($statLineData[0]) === 'MemFree') {
                        $memoryFree = trim($statLineData[1]);
                        $memoryFree = explode(' ', $memoryFree);
                        $memoryFree = $memoryFree[0];
                        $memoryFree *= 1024;  // convert from kibibytes to bytes
                    }
                }
            }
        }

        return array(
            'total' => $memoryTotal,
            'free' => $memoryFree,
        );
    }
}