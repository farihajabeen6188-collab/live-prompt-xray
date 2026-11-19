<?php

declare(strict_types=1);

namespace XRay\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use XRay\Instrumentation\Mcp\McpToolDirectory;
use XRay\Instrumentation\Mcp\TappingTransport;
use XRay\Instrumentation\TracingAgent;
use XRay\Instrumentation\TracingPlatform;
use XRay\Instrumentation\TracingRetriever;
use XRay\Instrumentation\TracingToolbox;
use XRay\Pricing\CostEstimator;
use XRay\Recorder\Recorder;

/**
 * Decorates the Symfony AI and MCP services X-Ray observes. Nothing is patched: every hook is a
 * service decorator, so removing the bundle (or `xray.enabled: false`) restores the original graph.
 */
final class InstrumentationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // The Recorder only exists when X-Ray is enabled (see XRayBundle::loadExtension()).
        if (!$container->hasDefinition(Recorder::class)) {
            return;
        }

        $recorder = new Reference(Recorder::class);
        $logger = new Reference('logger', ContainerBuilder::NULL_ON_INVALID_REFERENCE);

        foreach (array_keys($container->findTaggedServiceIds('ai.agent')) as $id) {
            $this->decorate($container, $id, 'agent', new Definition(TracingAgent::class, [new Reference('.inner'), $recorder]));
        }
        foreach (array_keys($container->findTaggedServiceIds('ai.platform')) as $id) {
            $this->decorate($container, $id, 'platform', new Definition(TracingPlatform::class, [new Reference('.inner'), $recorder, new Reference(CostEstimator::class), $logger]));
        }
        // Priority 0 sits inside a fault tolerant toolbox (-1024): failures are seen before they become messages.
        foreach (array_keys($container->findTaggedServiceIds('ai.toolbox')) as $id) {
            $this->decorate($container, $id, 'toolbox', new Definition(TracingToolbox::class, [new Reference('.inner'), $recorder, new Reference(McpToolDirectory::class)]));
        }
        foreach ($container->findTaggedServiceIds('ai.retriever') as $id => $tags) {
            $name = \is_string($tags[0]['name'] ?? null) ? $tags[0]['name'] : $id;
            $this->decorate($container, $id, 'retriever', new Definition(TracingRetriever::class, [new Reference('.inner'), $recorder, $name]));
        }
        foreach ($container->findTaggedServiceIds('mcp.client.connection') as $id => $tags) {
            if (!$container->hasDefinition($id.'.transport')) {
                continue;
            }
            $server = \is_string($tags[0]['server'] ?? null) ? $tags[0]['server'] : $id;
            $this->decorate($container, $id.'.transport', 'mcp_transport', new Definition(TappingTransport::class, [new Reference('.inner'), $recorder, $server, new Reference(McpToolDirectory::class)]));
        }
    }

    private function decorate(ContainerBuilder $container, string $id, string $kind, Definition $decorator): void
    {
        $container->setDefinition(\sprintf('xray.%s.%s', $kind, $id), $decorator->setDecoratedService($id, null, 0));
    }
}
