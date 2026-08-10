<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\AiUnavailableException;
use App\Ai\ChatProviderException;
use App\Entity\AgentConversation;
use App\Entity\Space;
use App\Entity\User;
use App\Service\AgentChatService;
use App\Service\AgentConversationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Talking to an agent (#827, step 3).
 *
 * **Any member of the agent's space may chat with it.** An agent is a space
 * resource, like a board — provisioning one is admin-gated (it mints a
 * credential), but using one is not. The conversation is still private per
 * person: {@see AgentConversation} is unique per (user, agent), so a member
 * reaches only their own thread and there is no id in any of these routes that
 * could address somebody else's.
 *
 * Not an API Platform resource. Sending a message is a *command with a cost* —
 * it reserves credits, calls a third party, and can fail in ways that have to be
 * told apart (see {@see send()}) — which is not what a REST collection POST
 * models well.
 */
final class AgentChatController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AgentConversationService $conversations,
        private readonly AgentChatService $chat,
    ) {
    }

    #[Route('/agents/{agentId}/chat', name: 'agent_chat_show', methods: ['GET'])]
    public function show(string $agentId, #[CurrentUser] ?User $user): JsonResponse
    {
        $resolved = $this->resolve($agentId, $user);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$agent, $space] = $resolved;
        \assert($user instanceof User);

        $conversation = $this->conversations->conversationFor($user, $agent, $space);
        // Reading a thread creates it on first open, which is what lets the
        // client render an empty conversation without a second round trip.
        $this->em->flush();

        return $this->json($this->serialize($conversation, $agent, $space));
    }

    #[Route('/agents/{agentId}/chat/messages', name: 'agent_chat_send', methods: ['POST'])]
    public function send(string $agentId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $resolved = $this->resolve($agentId, $user);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$agent, $space] = $resolved;
        \assert($user instanceof User);

        $payload = $request->toArray();
        $body = is_string($payload['body'] ?? null) ? trim($payload['body']) : '';
        if ('' === $body) {
            return $this->json(['error' => 'A message is required.'], 422);
        }
        if (mb_strlen($body) > AgentConversationService::MAX_MESSAGE_LENGTH) {
            return $this->json([
                'error' => sprintf(
                    'A message can be at most %d characters.',
                    AgentConversationService::MAX_MESSAGE_LENGTH,
                ),
            ], 422);
        }

        $conversation = $this->conversations->conversationFor($user, $agent, $space);

        try {
            [$question, $answer] = $this->conversations->send($conversation, $body);
        } catch (AiUnavailableException $e) {
            // Two different problems wearing one exception. A plan or credit
            // limit is about *this account* and is something the customer can
            // act on — 402. A missing provider is about the instance and is
            // nobody-in-this-request's fault — 503. Collapsing them to one
            // status would send half of these users to a pricing page that
            // cannot help them.
            $status = in_array(
                $e->reason,
                [AiUnavailableException::REASON_PLAN, AiUnavailableException::REASON_CREDITS],
                true,
            ) ? 402 : 503;

            return $this->json(['error' => $e->getMessage(), 'reason' => $e->reason], $status);
        } catch (ChatProviderException $e) {
            return $this->json([
                'error' => $e->retryable
                    ? 'The AI service is busy. Try again in a moment.'
                    : 'The AI service could not answer that.',
                'retryable' => $e->retryable,
            ], 503);
        }

        // The person's message was rolled back with the failure above, so a
        // 200 is the only case where either of these exists.
        return $this->json([
            'userMessage' => $question->toArray(),
            'assistantMessage' => $answer->toArray(),
        ], 201);
    }

    #[Route('/agents/{agentId}/chat', name: 'agent_chat_clear', methods: ['DELETE'])]
    public function clear(string $agentId, #[CurrentUser] ?User $user): JsonResponse
    {
        $resolved = $this->resolve($agentId, $user);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$agent] = $resolved;
        \assert($user instanceof User);

        $existing = $this->em->getRepository(AgentConversation::class)
            ->findOneBy(['user' => $user, 'agent' => $agent]);
        if ($existing instanceof AgentConversation) {
            $this->conversations->clear($existing);
        }

        return $this->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(AgentConversation $conversation, User $agent, Space $space): array
    {
        $messages = [];
        foreach ($conversation->getMessages() as $message) {
            $messages[] = $message->toArray();
        }

        // The same availability the composer needs to decide whether to let
        // someone type at all — returned with the thread so opening a chat is
        // one request rather than two.
        $unavailable = $this->chat->unavailableReason($space);

        return [
            'id' => (string) $conversation->getId(),
            'agent' => [
                'id' => (string) $agent->getId(),
                'name' => $agent->getNickname() ?? $agent->getGivenName(),
                'personalizedColor' => $agent->getPersonalizedColor(),
            ],
            'space' => ['id' => (string) $space->getId(), 'name' => $space->getName()],
            'messages' => $messages,
            'unavailableReason' => $unavailable?->reason,
            'unavailableMessage' => $unavailable?->getMessage(),
            'maxMessageLength' => AgentConversationService::MAX_MESSAGE_LENGTH,
        ];
    }

    /**
     * Resolve the agent and its space, enforcing that the caller is a member of
     * that space.
     *
     * Everything that isn't "an agent you can reach" is a 404, including a
     * perfectly real human user id: these routes must not become a way to probe
     * which accounts exist or which of them are agents.
     *
     * @return array{0: User, 1: Space}|JsonResponse
     */
    private function resolve(string $agentId, ?User $user): array|JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($agentId)) {
            return $this->json(['error' => 'Agent not found.'], 404);
        }
        $agent = $this->em->getRepository(User::class)->find($agentId);
        if (!$agent instanceof User || !$agent->isAgent()) {
            return $this->json(['error' => 'Agent not found.'], 404);
        }
        $space = $this->conversations->spaceOf($agent);
        if (null === $space) {
            return $this->json(['error' => 'Agent not found.'], 404);
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$space->hasMember($user)) {
            return $this->json(['error' => 'Agent not found.'], 404);
        }

        return [$agent, $space];
    }
}
