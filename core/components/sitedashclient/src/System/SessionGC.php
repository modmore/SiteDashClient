<?php

namespace modmore\SiteDashClient\System;

use modCacheManager;
use modmore\SiteDashClient\CommandInterface;

class SessionGC implements CommandInterface {
    public function run()
    {
        $cleared = session_gc();
        echo json_encode([
            'success' => $cleared !== false,
            'messaged' => "Cleared {$cleared} sessions.",
            'cleared' => (int)$cleared,
        ], JSON_PRETTY_PRINT);
    }
}
