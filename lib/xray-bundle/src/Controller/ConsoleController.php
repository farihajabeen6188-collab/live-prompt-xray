<?php

declare(strict_types=1);

namespace XRay\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\HubInterface;
use Twig\Environment;

final readonly class ConsoleController
{
    public function __construct(
        private Environment $twig,
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
        ]));
    }

    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok', 'transport' => $this->transport]);
    }
}
