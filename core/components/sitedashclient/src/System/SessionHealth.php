<?php

namespace modmore\SiteDashClient\System;

use modmore\SiteDashClient\CommandInterface;

class SessionHealth implements CommandInterface {
    protected $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    public function run()
    {
        $health = $this->getHealth();

        // Output the requested info
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $health,
        ], JSON_PRETTY_PRINT);
    }

    protected function getHealth()
    {
        $health = [];

        $name = $this->modx->getTableName('modSession');
        if ($statusQuery = $this->modx->query('CHECK TABLE ' . $name)) {
            $status = $statusQuery->fetchAll(\PDO::FETCH_ASSOC);
            $i = [];
            foreach ($status as $s) {
                $i[$s['Msg_type']] = $s['Msg_text'];
            }
            $health['session_table'] = json_encode($i);
        }

        $c = 'SELECT TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES WHERE table_schema = ' . $this->modx->quote($this->modx->connection->config['dbname']) . ' AND table_name = ' . $this->modx->quote(trim($name, '`'));

        if ($sizeQuery = $this->modx->query($c)) {
            $rows = $sizeQuery->fetchAll(\PDO::FETCH_ASSOC);
            $health['session_sizes'] = reset($rows);
        }

        if ($lastQuery = $this->modx->query('SELECT access FROM ' . $name . ' ORDER BY access ASC LIMIT 1')) {
            $health['session_oldest'] = $lastQuery->fetchColumn();
        }

        return $health;
    }
}

