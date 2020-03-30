<?php

namespace modmore\SiteDashClient\System;

use modmore\SiteDashClient\CommandInterface;

class Status implements CommandInterface {
    /**
     * @var \modX
     */
    private $modx;
    private $_cpuCountMethod = '';

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
            'cpu_count_method' => $this->_cpuCountMethod,
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
        // If /proc/stat is available, we can parse through that and get a count of lines starting with "cpu" to get the cores
        if (@is_file('/proc/stat') && @is_readable('/proc/stat')) {
            $stat = file_get_contents('/proc/stat');
            $stat = explode("\n", $stat);

            $numCpus = -1; // starting at -1 because the first match is a total line
            foreach ($stat as $line) {
                if (strpos($line, 'cpu') === 0) {
                    $numCpus++;
                }
            }

            $this->_cpuCountMethod = '/proc/stat';
            return $numCpus;
        }

        // Proc/cpuinfo is similar
        if (@is_file('/proc/cpuinfo') && @is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            $cpuinfo = explode("\n", $cpuinfo);
            $numCores = 0;
            foreach ($cpuinfo as $line) {
                if (strpos($line, 'cpu cores') === 0) {
                    $numCores += (int)substr($line, strpos(':', $line) + 1);
                }
            }

            $this->_cpuCountMethod = '/proc/cpuinfo';
            return $numCores;
        }

        // Windows
        if (function_exists('popen') && stripos(PHP_OS, 'WIN') === 0) {
            $process = @popen('wmic cpu get NumberOfCores', 'rb');
            if ($process !== false) {
                fgets($process);
                $numCpus = (int)fgets($process);
                pclose($process);

                $this->_cpuCountMethod = 'wmic';
                return $numCpus;
            }
        }

        $this->_cpuCountMethod = 'notavailable';
        return 0;
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
        elseif (@is_file('/proc/meminfo') && @is_readable('/proc/meminfo')) {
            $stats = file_get_contents('/proc/meminfo');

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
                    if (count($statLineData) === 2 && trim($statLineData[0]) === 'MemAvailable') {
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