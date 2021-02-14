<?php

namespace modmore\SiteDashClient\System;

use modCacheManager;
use modmore\SiteDashClient\CommandInterface;

class SessionGC implements CommandInterface
{
    /** @var \modX  */
    protected $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    public function run()
    {
        // Work-around a lack of session results by comparing a before/after count if session_gc returns 1 https://github.com/modxcms/revolution/pull/15393
        // @todo replace this with a version check once merged, to avoid the getCount() lookup time when unnecessary
        $before = $this->modx->getCount('modSession');
        $cleared = session_gc();

        if ($cleared === 1) {
            $cleared = $before - $this->modx->getCount('modSession');
        }

        // Get updated session health data
        $health = [];

        $name = $this->modx->getTableName('modSession');
        $c = 'SELECT TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES WHERE table_schema = ' . $this->modx->quote($this->modx->connection->config['dbname']) . ' AND table_name = ' . $this->modx->quote(trim($name, '`'));

        if ($sizeQuery = $this->modx->query($c)) {
            $rows = $sizeQuery->fetchAll(\PDO::FETCH_ASSOC);
            $health['session_sizes'] = reset($rows);
        }

        if ($lastQuery = $this->modx->query('SELECT access FROM ' . $name . ' ORDER BY access ASC LIMIT 1')) {
            $health['session_oldest'] = $lastQuery->fetchColumn();
        }

        // Return it all to the client
        echo json_encode([
            'success' => $cleared !== false,
            'messaged' => "Cleared {$cleared} sessions.",
            'cleared' => (int)$cleared,
            'health' => $health,
        ], JSON_PRETTY_PRINT);
    }
}
