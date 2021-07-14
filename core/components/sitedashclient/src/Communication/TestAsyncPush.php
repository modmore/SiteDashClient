<?php

namespace modmore\SiteDashClient\Communication;

use modmore\SiteDashClient\CommandInterface;

class TestAsyncPush implements CommandInterface {
    /**
     * @var Pusher
     */
    private $pusher;

    public function __construct($pusher)
    {
        $this->pusher = $pusher;
    }

    public function run()
    {
        $this->pusher->acknowledge();
        $result = new Result($this->pusher);

        $logFile = MODX_CORE_PATH . 'cache/logs/sitedash_pushtest_' . date('Y-m-d-H-i-s') . '.log';
        $limit = 15;
        $i = 0;
        @set_time_limit($limit + 5);
        while ($i < $limit) {
            $i++;
            file_put_contents($logFile, date('Y-m-d H:i:s') . "\n", FILE_APPEND);
            sleep(1);
        }

        $result(200, [
            'success' => true,
            'counted_to' => $limit,
        ]);
    }
}
