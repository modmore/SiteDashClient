<?php
namespace modmore\SiteDashClient\Package;

use modmore\SiteDashClient\CommandInterface;
use MODX\Revolution\Transport\modTransportPackage;
use MODX\Revolution\Transport\modTransportProvider;
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

        /** @var \modTransportPackage|modTransportPackage $package */
        $c = $this->modx->newQuery('transport.modTransportPackage');
        $c->where([
            'signature:LIKE' => $packageName . '-%',
            'AND:installed:!=' => true, // if a package is uninstalled, we don't want to force a check
        ]);
        $c->sortby('installed', 'DESC');
        $c->limit(1);
        $package = $this->modx->getObject('transport.modTransportPackage', $c);
        if (!$package) {
            throw new RuntimeException('Package not installed.');
        }

        /** @var \modTransportProvider|modTransportProvider $provider */
        $provider = $package->getOne('Provider');
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
