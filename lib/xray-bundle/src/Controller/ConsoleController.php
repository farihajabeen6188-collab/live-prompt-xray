<?php

declare(strict_types=1);

namespace XRay\Controller;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\HubInterface;
use Twig\Environment;

final readonly class ConsoleController
{
    public function __construct(
        private Environment $twig,
        private AssetMapperInterface $assets,
        #[Autowire(param: 'xray.transport')]
        private string $transport,
        #[Autowire(param: 'xray.run_url')]
        private ?string $runUrl,
        private ?HubInterface $hub = null,
    ) {
    }

    public function console(): Response
    {
        return new Response($this->twig->render('@XRay/console.html.twig', [
            'xray_config' => [
                'transport' => null !== $this->hub && 'mercure' === $this->transport ? 'mercure' : 'sse',
                'hubUrl' => 'mercure' === $this->transport ? $this->hub?->getPublicUrl() : null,
                'topicPrefix' => 'xray/runs/',
                'runUrl' => $this->runUrl,
            ],
            'xray_importmap' => $this->importMap(),
        ]));
    }

    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok', 'transport' => $this->transport]);
    }

    /**
     * The console's ES modules import each other relatively. The browser resolves those imports to
     * unversioned URLs, so this import map points each of them at its fingerprinted asset; the host
     * app's importmap.php is left alone.
     *
     * @return array{imports: array<string, string>}
     */
    private function importMap(): array
    {
        $imports = [];
        foreach ($this->assets->allAssets() as $asset) {
            if (str_starts_with($asset->logicalPath, 'xray/') && str_ends_with($asset->logicalPath, '.js')) {
                $imports[$asset->publicPathWithoutDigest] = $asset->publicPath;
            }
        }

        return ['imports' => $imports];
    }
}
