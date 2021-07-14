<?php

namespace modmore\SiteDashClient\Communication;

final class Pusher {
    private $responseUri;

    public function __construct($server, $responseUri)
    {
        $this->responseUri = $server . $responseUri;
    }

    public function acknowledge()
    {
        ob_start();

        echo json_encode([
            'return_push' => true,
        ]);

        // Get the size of the output.
        $size = ob_get_length();

        // 202 accepted
        http_response_code(202);

        // Disable compression (in case content length is compressed).
        header('Content-Encoding: none');

        // Set the content length of the response.
        header("Content-Length: {$size}");

        // Close the connection.
        header('Connection: close');

        // Flush all output.
        ob_end_flush();
        ob_flush();
        flush();

        ignore_user_abort(true);
        @session_write_close();

        if (is_callable('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }
        sleep(1);
    }

    public function push(array $data)
    {
        $logFile = MODX_CORE_PATH . 'cache/logs/sitedash_push_' . date('Y-m-d-H-i-s') . '.log';

        $ch = curl_init();

        $postData = $this->prepareData($data);
        curl_setopt($ch, CURLOPT_URL, $this->responseUri);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $errno    = curl_errno($ch);
        curl_close($ch);

        $dataFormat = json_encode($data, JSON_PRETTY_PRINT);
        $postDataFormat = json_encode($postData, JSON_PRETTY_PRINT);
        $log = <<<HTML
Push request to {$this->responseUri}:

Data: {$dataFormat}

Data to post to SiteDash, incl signature: {$postDataFormat}

Response from SiteDash: {$errno} {$error}

    {$response}
HTML;

        file_put_contents($logFile, $log);
    }

    private function prepareData(array $data)
    {
        return $data;
    }
}
