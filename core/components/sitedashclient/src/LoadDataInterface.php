<?php
namespace modmore\SiteDashClient;

interface LoadDataInterface
{
    public function __construct(\modX $modx, array $params);
    public function run();
}