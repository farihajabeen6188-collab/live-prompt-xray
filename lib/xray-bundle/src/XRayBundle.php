<?php

declare(strict_types=1);

namespace XRay;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class XRayBundle extends AbstractBundle
{
    protected string $extensionAlias = 'xray';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->info('Set to false to remove every X-Ray service and decorator.')->end()
            ->end();
    }

    /**
     * @param array{enabled: bool} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->parameters()->set('xray.enabled', $config['enabled']);
        if (!$config['enabled']) {
            return;
        }
        $container->import('../config/services.php');
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
