<?php

declare(strict_types=1);

namespace XRay;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use XRay\DependencyInjection\InstrumentationPass;
use XRay\Transport\EventPublisher;
use XRay\Transport\MercurePublisher;
use XRay\Transport\NullPublisher;

final class XRayBundle extends AbstractBundle
{
    protected string $extensionAlias = 'xray';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('enabled')->defaultTrue()->info('Set to false to remove every X-Ray service and decorator.')->end()
                ->enumNode('transport')->values(['auto', 'mercure', 'sse'])->defaultValue('auto')
                    ->info('"mercure" pushes events through a Mercure hub; "sse" streams them from the trace store (no hub needed); "auto" picks mercure when MercureBundle is enabled.')->end()
                ->stringNode('storage_path')->defaultValue('%kernel.project_dir%/var/xray/traces.sqlite')->end()
                ->arrayNode('redact_keys')
                    ->info('Values under these keys never leave the process. Matching ignores case, "-" and "_", and also hits suffixes ("token" hides "access_token").')
                    ->scalarPrototype()->end()
                    ->defaultValue(['api_key', 'apikey', 'authorization', 'token', 'secret', 'password'])
                ->end()
                ->stringNode('run_url')->defaultNull()
                    ->info('Endpoint of your app that starts an agent run (GET lists scenarios, POST runs a prompt). Without it the console is a viewer.')->end()
                ->integerNode('max_string_bytes')->min(256)->defaultValue(8192)->info('Longer strings are truncated in traces.')->end()
                ->arrayNode('prices')
                    ->info('USD per 1M tokens, keyed by model name, to estimate cost: { "gpt-4o": { input: 2.5, output: 10 } }.')
                    ->useAttributeAsKey('model')
                    ->arrayPrototype()
                        ->children()
                            ->floatNode('input')->isRequired()->end()
                            ->floatNode('output')->isRequired()->end()
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
            ->end();
    }

    /**
     * @param array{enabled: bool, transport: string, storage_path: string, run_url: ?string, redact_keys: list<string>, max_string_bytes: int, prices: array<string, array{input: float, output: float}>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The switch decides which services exist, so an env value ("%env(bool:XRAY_ENABLED)%") is read
        // when the container is built: change it, then clear the cache.
        $config['enabled'] = filter_var($builder->resolveEnvPlaceholders($config['enabled'], true), \FILTER_VALIDATE_BOOL);
        if ('auto' === $config['transport']) {
            $config['transport'] = $builder->hasExtension('mercure') ? 'mercure' : 'sse';
        }

        $container->parameters()
            ->set('xray.enabled', $config['enabled'])
            ->set('xray.transport', $config['transport'])
            ->set('xray.storage_path', $config['storage_path'])
            ->set('xray.redact_keys', $config['redact_keys'])
            ->set('xray.max_string_bytes', $config['max_string_bytes'])
            ->set('xray.prices', $config['prices'])
            ->set('xray.run_url', $config['run_url']);

        if (!$config['enabled']) {
            return;
        }
        $container->import('../config/services.php');

        $publisher = 'mercure' === $config['transport'] ? MercurePublisher::class : NullPublisher::class;
        $container->services()->set('xray.publisher', $publisher)->autowire();
        $container->services()->alias(EventPublisher::class, 'xray.publisher');
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new InstrumentationPass());
    }

    /**
     * Serves the console's CSS and ES modules through AssetMapper as "xray/…" (no build step).
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // FrameworkBundle only enables assets on its own when symfony/asset is a root dependency.
        $builder->prependExtensionConfig('framework', [
            'assets' => ['enabled' => true],
            'asset_mapper' => ['enabled' => true, 'paths' => [\dirname(__DIR__).'/assets' => 'xray']],
        ]);
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
