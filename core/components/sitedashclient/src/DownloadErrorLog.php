<?php

namespace modmore\SiteDashClient;

class DownloadErrorLog implements LoadDataInterface {
    protected $modx;
    protected $params = array();

    public function __construct(\modX $modx, array $params)
    {
        $this->modx = $modx;
        $this->params = $params;
    }

    public function run()
    {
        $file = $this->modx->getOption('cache_path') . 'logs/error.log';
        if (!file_exists($file)) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'data' => [
                    'message' => 'Error log not found'
                ]
            ]);
            return;
        }

        http_response_code(200);
        header('Content-Length: ' . filesize($file));
        readfile($file);
    }
}