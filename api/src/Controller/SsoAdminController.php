<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SsoConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin management of the sign-in providers (#267). ROLE_ADMIN via
 * security.yaml (`^/admin/sso`). Secrets are write-only: reads return only
 * presence flags, writes replace a secret only when a new value is supplied.
 */
#[Route('/admin/sso')]
final class SsoAdminController extends AbstractController
{
    public function __construct(private readonly SsoConfig $config)
    {
    }

    #[Route('', name: 'admin_sso_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json([
            'providers' => array_values($this->config->redactedAll()),
            'specs' => SsoConfig::PROVIDERS,
        ]);
    }

    #[Route('/{provider}', name: 'admin_sso_update', methods: ['PUT'])]
    public function update(string $provider, Request $request): JsonResponse
    {
        if (!isset(SsoConfig::PROVIDERS[$provider])) {
            return $this->json(['error' => 'Unknown provider.'], 404);
        }
        $this->config->update($provider, $request->toArray());

        return $this->json(['provider' => $this->config->redactedAll()[$provider] ?? null]);
    }
}
