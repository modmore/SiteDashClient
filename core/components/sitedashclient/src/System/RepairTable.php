<?php

namespace modmore\SiteDashClient\System;

use modmore\SiteDashClient\LoadDataInterface;

class RepairTable implements LoadDataInterface {
    protected $modx;
    protected $params = array();

    public function __construct(\modX $modx, array $params)
    {
        $this->modx = $modx;
        $this->params = $params;
    }

    public function run()
    {
        $class = array_key_exists('class', $this->params) ? (string)$this->params['class'] : false;
        if ($name = $this->modx->getTableName($class)) {
            if ($statusQuery = $this->modx->query('REPAIR TABLE ' . $name)) {
                $status = $statusQuery->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($status as $s) {
                    if ($s['Msg_type'] === 'status') {
                        http_response_code(200);
                        echo json_encode([
                            'success' => true,
                            'data' => [
                                'class' => $class,
                                'status' => $s['msg_text'],
                            ],
                        ], JSON_PRETTY_PRINT);

                        return;
                    }
                }
            }
        }

        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Could not repair the requested table.'
        ], JSON_PRETTY_PRINT);
    }
}
