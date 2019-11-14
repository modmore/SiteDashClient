<?php

namespace modmore\SiteDashClient;

class DownloadErrorLog implements CommandInterface {
    protected $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    public function run()
    {
        // Support custom error log names/paths
        $filename = $this->modx->getOption('error_log_filename', null, 'error.log', true);
        $filepath = $this->modx->getOption('error_log_filepath', null, $this->modx->getCachePath() . 'logs/', true);
        $file = rtrim($filepath, '/') . '/' . $filename;
        if (!file_exists($file)) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Error log not found',
                'data' => [
                    'path' => str_replace(MODX_CORE_PATH, '{core_path}', $filepath . $filename),
                ],
            ]);
            return;
        }

        http_response_code(200);
        header('Content-Length: ' . filesize($file));
        readfile($file);
    }
}