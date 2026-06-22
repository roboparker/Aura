<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\UsageLimiter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Per-user automation usage for the settings "Usage & plan" surface
 * (#billing). Reports today's metered MCP usage against the free daily cap,
 * plus whether the caller is entitled (belongs to a paid Team space) — in
 * which case throughput is unlimited (`remaining: null`).
 *
 * `enforcementEnabled` mirrors the dark-launch flag so the UI can phrase the
 * limit as a live cap vs. a preview while billing isn't yet switched on.
 */
class UsageController extends AbstractController
{
    public function __construct(private UsageLimiter $usageLimiter)
    {
    }

    #[Route('/me/usage', name: 'me_usage', methods: ['GET'])]
    public function usage(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        return $this->json([
            'mcpCallsToday' => $this->usageLimiter->mcpCallsToday($user),
            'freeMcpDailyLimit' => $this->usageLimiter->freeMcpDailyLimit(),
            'remaining' => $this->usageLimiter->remainingMcpCalls($user),
            'entitled' => $this->usageLimiter->isUserEntitled($user),
            'enforcementEnabled' => $this->usageLimiter->isEnforcementEnabled(),
        ]);
    }
}
