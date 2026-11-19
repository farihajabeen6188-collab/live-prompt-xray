<?php

declare(strict_types=1);

namespace App\Platform;

use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Picks the demo agent's platform from XRAY_PLATFORM: "demo" (scripted model) or "ollama" (a real local model).
 */
final readonly class PlatformSelector
{
    /**
     * @param ServiceLocator<PlatformInterface> $platforms
     */
    public function __construct(
        private ServiceLocator $platforms,
        #[Autowire(env: 'XRAY_PLATFORM')]
        private string $mode,
    ) {
    }

    public function platform(): PlatformInterface
    {
        if (!$this->platforms->has($this->mode)) {
            throw new \InvalidArgumentException(\sprintf('XRAY_PLATFORM must be "demo" or "ollama", got "%s".', $this->mode));
        }

        return $this->platforms->get($this->mode);
    }

    public function mode(): string
    {
        return 'demo' === $this->mode ? 'demo' : 'live';
    }
}
