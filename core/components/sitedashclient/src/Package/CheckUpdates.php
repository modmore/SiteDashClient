<?php

namespace modmore\SiteDashClient\Package;

use modmore\SiteDashClient\CommandInterface;
use modTransportPackage;
use modTransportProvider;
use RuntimeException;

class CheckUpdates implements CommandInterface
{
    protected $modx;
    protected $packages = [];

    public function __construct(\modX $modx, $packages = '')
    {
        $this->modx = $modx;
        $this->packages = array_filter(array_map('trim', explode(',', $packages)));
    }

    public function run()
    {
        $details = [];
        foreach ($this->packages as $package) {
            try {
                $details[$package] = $this->checkPackage($package);
            } catch (\Exception $e) {
                $details[$package] = ['error' => $e->getMessage()];
            }
        }

        $return = [
            'success' => true,
            'details' => $details,
        ];

        http_response_code(200);
        echo json_encode($return, JSON_PRETTY_PRINT);
    }

    protected function checkPackage($packageName): array
    {
        if ($packageName === '') {
            throw new RuntimeException('Empty package name');
        }

        /** @var modTransportPackage|\MODX\Revolution\Transport\modTransportPackage $package */
        $package = $this->modx->getObject('transport.modTransportPackage', [
            'signature:LIKE' => $packageName . '-%',
            'AND:installed:=' => true,
        ]);

        if (!$package) {
            throw new RuntimeException('Package not installed.');
        }
        $this->log('Found package ' . $package->get('signature'));

        /** @var modTransportProvider|\MODX\Revolution\Transport\modTransportPackage $provider */
        $provider =& $package->getOne('Provider');
        if (!$provider) {
            throw new RuntimeException('No provider associated with package.');
        }

        $updates = [];
        $available = $provider->latest($package->get('signature'));
        foreach ($available as $updateOption) {
            $updates[] = [
                'signature' => $updateOption['display_name'],
                'releasedon' => $updateOption['releasedon'],
            ];
        }
        return [
            'provider' => $provider->get('name'),
            'installed' => $package->get('signature'),
            'updates' => $updates,
        ];
    }
}
