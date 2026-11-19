<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use XRay\Controller\ConsoleController;

return static function (RoutingConfigurator $routes): void {
    $routes->add('xray_console', '/_xray')
        ->controller([ConsoleController::class, 'console'])
        ->methods(['GET']);
    $routes->add('xray_health', '/_xray/health')
        ->controller([ConsoleController::class, 'health'])
        ->methods(['GET']);
    $routes->add('xray_spike','/_xray/spike')
        ->controller([ConsoleController::class, 'spike'])
        ->methods(['POST']);
};
