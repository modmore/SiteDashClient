<?php

namespace modmore\SiteDashClient\Communication;

final class Result {
    /**
     * @var Pusher|null
     */
    private $pusher;

    public function __construct(Pusher $pusher = null)
    {
        $this->pusher = $pusher;
    }

    public function __invoke($responseCode, $data)
    {
        if ($this->pusher) {
            $this->pusher->push($data);
        }
        else {
            http_response_code($responseCode);
            echo json_encode($data, JSON_PRETTY_PRINT);
            @session_write_close();
            exit();
        }
    }
}