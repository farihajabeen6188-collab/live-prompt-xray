<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use XRay\Controller\ConsoleController;
use XRay\Transport\EventPublisher;
use XRay\Transport\MercurePublisher;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    $services->set(MercurePublisher::class);
    $services->alias(EventPublisher::class, MercurePublisher::class);

    $services->set(ConsoleController::class)->public();
};
