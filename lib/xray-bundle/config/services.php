<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use XRay\Controller\ConsoleController;
use XRay\Controller\PreviewController;
use XRay\Controller\StreamController;
use XRay\Controller\TraceController;
use XRay\Diff\TraceDiffer;
use XRay\Instrumentation\Mcp\McpToolDirectory;
use XRay\Preview\PromptPreviewer;
use XRay\Pricing\CostEstimator;
use XRay\Recorder\Recorder;
use XRay\Recorder\Redactor;
use XRay\Recorder\RunContext;
use XRay\Replay\TraceFile;
use XRay\Storage\SqliteTraceStore;
use XRay\Storage\TraceStore;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    $services->set(Redactor::class)->args([param('xray.redact_keys'), param('xray.max_string_bytes')]);
    $services->set(RunContext::class)->tag('kernel.reset', ['method' => 'reset']);
    $services->set(SqliteTraceStore::class)->args([param('xray.storage_path')]);
    $services->alias(TraceStore::class, SqliteTraceStore::class);
    $services->set(CostEstimator::class)->args([param('xray.prices')]);
    $services->set(McpToolDirectory::class);
    $services->set(Recorder::class)
        ->args([service(RunContext::class), service(Redactor::class), service(TraceStore::class), service('xray.publisher'), service('logger')->nullOnInvalid()])
        ->public();
    $services->set(TraceFile::class);
    $services->set(TraceDiffer::class);
    // "$agents" is filled by InstrumentationPass from the agents, their input processors and toolboxes.
    $services->set(PromptPreviewer::class)->arg('$agents', [])->arg('$contextWindow', param('xray.context_window'));

    $services->set(ConsoleController::class)->public();
    $services->set(StreamController::class)->public();
    $services->set(TraceController::class)->public();
    $services->set(PreviewController::class)->public();
};
