<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController
{
    #[Route('/', methods: ['GET'])]
    public function __invoke(): RedirectResponse
    {
        return new RedirectResponse('/_xray');
    }
}
