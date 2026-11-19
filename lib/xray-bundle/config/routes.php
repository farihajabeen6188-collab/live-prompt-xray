<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use XRay\Controller\ConsoleController;
use XRay\Controller\PreviewController;
use XRay\Controller\StreamController;
use XRay\Controller\TraceController;

return static function (RoutingConfigurator $routes): void {
    $run = ['id' => 'run_[A-Za-z0-9]{8,40}'];

    $routes->add('xray_console', '/_xray')->controller([ConsoleController::class, 'console'])->methods(['GET']);
    $routes->add('xray_health', '/_xray/health')->controller([ConsoleController::class, 'health'])->methods(['GET']);
    $routes->add('xray_runs', '/_xray/runs')->controller([TraceController::class, 'list'])->methods(['GET']);
    $routes->add('xray_preview', '/_xray/preview')->controller(PreviewController::class)->methods(['POST']);
    $routes->add('xray_import', '/_xray/import')->controller([TraceController::class, 'import'])->methods(['POST']);
    $routes->add('xray_diff', '/_xray/diff')->controller([TraceController::class, 'diff'])->methods(['GET']);
    $routes->add('xray_run', '/_xray/runs/{id}')->controller([TraceController::class, 'show'])->requirements($run)->methods(['GET']);
    $routes->add('xray_export', '/_xray/runs/{id}/export')->controller([TraceController::class, 'export'])->requirements($run)->methods(['GET']);
    $routes->add('xray_stream', '/_xray/runs/{id}/stream')->controller([StreamController::class, 'stream'])->requirements($run)->methods(['GET']);
    $routes->add('xray_replay', '/_xray/runs/{id}/replay')->controller([StreamController::class, 'replay'])->requirements($run)->methods(['GET']);
};
