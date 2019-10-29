<?php

namespace modmore\SiteDashClient\System;

use modCacheManager;
use modmore\SiteDashClient\CommandInterface;

class ClearCache implements CommandInterface {
    /**
     * @var modCacheManager
     */
    private $cacheManager;
    /**
     * @var bool
     */
    private $force;

    public function __construct(\modX $modx, $force = false)
    {
        $this->force = $force;
        $this->cacheManager = $modx->getCacheManager();
    }

    public function run()
    {
        $return = $this->force ? $this->forceClearCache() : $this->clearCache();
        http_response_code($return['success'] ? 200 : 500);
        echo json_encode($return, JSON_PRETTY_PRINT);
    }

    public function clearCache(): array
    {
        $results = [];
        $this->cacheManager->refresh([], $results);

        return [
            'success' => true,
            'mode' => 'regular',
            'results' => $results,
        ];
    }

    public function forceClearCache(): array
    {
        $rootPath = $this->cacheManager->getCachePath();

        $paths = array_diff(scandir($rootPath), ['..', '.', 'logs']);
        $results = [];
        foreach ($paths as $subPath) {
            $deletedPaths = $this->cacheManager->deleteTree($rootPath . $subPath . '/', ['deleteTop' => true, 'extensions' => []]);
            $results[$subPath] = is_array($deletedPaths) && count($deletedPaths) > 0;
        }
        if (!empty($results)) {
            return [
                'success' => true,
                'mode' => 'force',
                'message' => 'Forcefully cleared cache directories: ' . implode(',', $results),
                'results' => $results
            ];
        }

        return [
            'success' => false,
            'message' => 'There were no cache directories to clear.',
            'mode' => 'regular',
            'results' => $results
        ];
    }
}