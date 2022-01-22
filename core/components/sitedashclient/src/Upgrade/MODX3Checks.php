<?php

namespace modmore\SiteDashClient\Upgrade;

use modmore\SiteDashClient\CommandInterface;
use modX;

class MODX3Checks implements CommandInterface {
    protected $modx;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
    }

    public function run()
    {
        $data = [
            'actions' => [],
            'eval_tvs' => [],
        ];

        foreach ($this->modx->getIterator('modAction') as $action) {
            $data['actions'][] = $action->get(['namespace', 'controller']);
        }

        foreach ($this->modx->getIterator('modTemplateVar', [
            'input_properties:LIKE' => "%@EVAL%",
            'OR:default_text:LIKE' => "%@EVAL%",

        ]) as $tv) {
            $data['eval_tvs'][] = $tv->get(['name', 'caption']);
        }

        // Output the requested info
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $data,
        ], JSON_PRETTY_PRINT);
    }
}
