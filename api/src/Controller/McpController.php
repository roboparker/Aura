<?php

namespace App\Controller;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Mcp\McpException;
use App\Mcp\McpToolPolicy;
use App\Mcp\McpToolRegistry;
use App\Mcp\Tool\McpToolInterface;
use App\Security\ApiTokenAuthenticator;
use App\Service\UsageLimiter;
use App\Service\UsageRecorder;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Single-endpoint MCP server (#92).
 *
 * Implements the JSON-RPC 2.0 surface that Anthropic's MCP clients drive:
 *  - `initialize` / `notifications/initialized` capability handshake
 *  - `tools/list` enumerates the registry
 *  - `tools/call` validates the requested tool, runs it as the bearer's
 *    owning user, and wraps the structured result in a single
 *    `content[].text` block (JSON-stringified) so legacy clients can
 *    still read results without relying on the structured-content extension
 *
 * Uses the modern Streamable HTTP transport: clients POST a JSON-RPC
 * request and read the JSON response from the same connection. We don't
 * open a server-initiated SSE channel today — there are no server-side
 * notifications to push — but the route accepts `text/event-stream` in
 * the Accept header for forward compatibility, returning the same JSON
 * payload either way (which the Streamable HTTP spec permits).
 *
 * Authentication is handled upstream by {@see ApiTokenAuthenticator}, so
 * by the time we land here `$user` is guaranteed non-null and the
 * matched {@see ApiToken} is on the request attribute bag.
 */
class McpController extends AbstractController
{
    public const PROTOCOL_VERSION = '2024-11-05';
    public const SERVER_NAME = 'madori-mcp';

    public function __construct(
        private McpToolRegistry $tools,
        private LoggerInterface $logger,
        private UsageRecorder $usage,
        private UsageLimiter $usageLimiter,
    ) {
    }

    #[Route('/mcp', name: 'mcp_endpoint', methods: ['POST', 'GET', 'OPTIONS'])]
    public function __invoke(Request $request, #[CurrentUser] ?User $user): Response
    {
        if ('OPTIONS' === $request->getMethod()) {
            // CORS preflight — let nelmio handle the headers, just confirm 204.
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        if ('GET' === $request->getMethod()) {
            // Streamable HTTP allows GET to open an SSE channel for
            // server-pushed notifications. We have nothing to push, so
            // the spec-friendly response is 405 — the client falls back
            // to plain POST/JSON, which works fine.
            return new JsonResponse(
                ['error' => 'GET stream is not supported by this server.'],
                Response::HTTP_METHOD_NOT_ALLOWED,
            );
        }

        if (!$user instanceof User) {
            // Belt-and-braces: the firewall should have rejected this.
            return new JsonResponse(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = $this->decode($request);
        if (null === $payload) {
            return new JsonResponse($this->jsonRpcError(null, -32700, 'Parse error.'));
        }

        // JSON-RPC 2.0 batch — array of envelopes. Run each in-order;
        // notifications produce no entry in the response.
        if (array_is_list($payload)) {
            $responses = [];
            foreach ($payload as $envelope) {
                // Belt + braces: a batch entry could be a non-array
                // (malformed client), drop those.
                if (!is_array($envelope)) {
                    continue;
                }
                /** @var array<string, mixed> $envelope */
                $resp = $this->dispatch($envelope, $request, $user);
                if (null !== $resp) {
                    $responses[] = $resp;
                }
            }
            return new JsonResponse($responses);
        }

        // phpstan can't narrow array_is_list()'s false branch back to
        // the string-keyed half of the union, so re-state the shape.
        /** @var array<string, mixed> $payload */
        $response = $this->dispatch($payload, $request, $user);
        if (null === $response) {
            // Notification — no response body, but HTTP 202 is the
            // streamable-HTTP convention for accepted notifications.
            return new Response('', Response::HTTP_ACCEPTED);
        }
        return new JsonResponse($response);
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>|null  null when the request is a notification
     */
    private function dispatch(array $envelope, Request $request, User $user): ?array
    {
        $id = $envelope['id'] ?? null;
        $method = $envelope['method'] ?? null;
        $params = $envelope['params'] ?? [];

        if (!is_string($method)) {
            return $this->jsonRpcError($id, -32600, 'Invalid Request: missing "method".');
        }
        $isNotification = !array_key_exists('id', $envelope);

        try {
            $result = match ($method) {
                'initialize' => $this->handleInitialize(),
                'ping' => new \stdClass(),
                'tools/list' => $this->handleToolsList(),
                'tools/call' => $this->handleToolsCall($params, $request, $user),
                default => null,
            };
        } catch (McpException $e) {
            // Tool-level errors come back as a successful JSON-RPC response
            // with `isError: true` so the model can read and recover.
            return $this->jsonRpcResult($id, [
                'isError' => true,
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('MCP dispatcher failure', [
                'method' => $method,
                'exception' => $e,
            ]);
            return $this->jsonRpcError($id, -32603, 'Internal server error.');
        }

        if ($isNotification) {
            // Per JSON-RPC 2.0, notifications produce no response.
            // `notifications/initialized` from the client lands here.
            return null;
        }

        if (null === $result) {
            return $this->jsonRpcError($id, -32601, sprintf('Method "%s" not found.', $method));
        }

        return $this->jsonRpcResult($id, $result);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleInitialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => '1.0.0',
            ],
            'capabilities' => [
                // We only advertise tools today. Resources and prompts can
                // be added later without a protocol bump.
                'tools' => ['listChanged' => false],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleToolsList(): array
    {
        $tools = [];
        foreach ($this->tools->all() as $tool) {
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
            ];
        }
        return ['tools' => $tools];
    }

    /**
     * @param array<string, mixed>|mixed $params
     * @return array<string, mixed>
     */
    private function handleToolsCall(mixed $params, Request $request, User $user): array
    {
        if (!is_array($params)) {
            throw McpException::invalidInput('tools/call params must be an object.');
        }
        $name = $params['name'] ?? null;
        if (!is_string($name) || '' === $name) {
            throw McpException::invalidInput('tools/call requires a "name".');
        }
        $tool = $this->tools->get($name);
        if (!$tool instanceof McpToolInterface) {
            throw McpException::notFound(sprintf('Tool "%s"', $name));
        }

        // Per-tool access-policy check on the matched ApiToken. A token with
        // no policy (the common case) is unrestricted; otherwise the tool's
        // category/action must be permitted by the policy.
        $apiToken = $request->attributes->get(ApiTokenAuthenticator::TOKEN_ATTR);
        if ($apiToken instanceof ApiToken) {
            // Space-scoped keys (#space-roles) authenticate as their creator and
            // are role/space-confined on REST; MCP tools query outside that
            // enforcement path, so scoped keys aren't supported over MCP yet.
            if ($apiToken->isScoped()) {
                throw McpException::forbidden('Space-scoped API keys cannot be used over MCP. Use a personal token.');
            }
            $policy = $apiToken->toAccessPolicy();
            if (null !== $policy && !McpToolPolicy::allows($policy, $name)) {
                throw McpException::forbidden(sprintf('Tool "%s" is outside this token\'s access scope.', $name));
            }
        }

        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw McpException::invalidInput('tools/call "arguments" must be an object.');
        }
        /** @var array<string, mixed> $arguments */

        // Freemium gate: free users get a daily MCP allowance; belonging to a
        // space on the paid Team plan lifts it. No-ops while billing
        // enforcement is dark. Checked before invoke so an over-limit call
        // does no work; surfaced as a recoverable isError so the model can
        // relay the upgrade prompt.
        if (!$this->usageLimiter->isMcpCallAllowed($user)) {
            throw McpException::forbidden(sprintf(
                'Daily free MCP limit reached (%d calls/day). Upgrade a space to the Team plan for unlimited automation.',
                $this->usageLimiter->freeMcpDailyLimit(),
            ));
        }

        $result = $tool->invoke($arguments, $user);

        // Count the successful tool dispatch against the bearer's owning
        // user. UsageRecorder swallows its own failures, so this can't break
        // the tool result.
        $this->usage->recordMcpCall($user);

        // Wrap the structured result so older clients that only read
        // `content[].text` still see the data. Newer clients read
        // `structuredContent` directly — we send both for compatibility.
        return [
            'content' => [
                ['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
            ],
            'structuredContent' => $result,
            'isError' => false,
        ];
    }

    /**
     * Decodes the request body. Returns a single JSON-RPC envelope as a
     * string-keyed array, a batch as a list of envelopes, or null on
     * empty/invalid input.
     *
     * @return array<string, mixed>|list<array<string, mixed>>|null
     */
    private function decode(Request $request): ?array
    {
        $body = $request->getContent();
        if ('' === $body) {
            return null;
        }
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        /** @var array<string, mixed>|list<array<string, mixed>> $decoded */
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonRpcResult(mixed $id, mixed $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonRpcError(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }
}
