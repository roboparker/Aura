<?php

namespace App\Mcp;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Argument coercion + validation glue shared across tools. The MCP
 * input schemas are advisory — Claude generally honours them, but the
 * server still has to police types because a buggy or older client
 * could send anything. Each tool calls into here so the rejection
 * messages stay consistent and tests don't have to repeat the same
 * assertions per tool.
 */
final class McpInputHelper
{
    public function __construct(private ValidatorInterface $validator)
    {
    }

    /**
     * @param array<string, mixed> $args
     */
    public function requireString(array $args, string $key): string
    {
        if (!array_key_exists($key, $args)) {
            throw McpException::invalidInput(sprintf('Missing required field "%s".', $key));
        }
        $value = $args[$key];
        if (!is_string($value) || '' === trim($value)) {
            throw McpException::invalidInput(sprintf('Field "%s" must be a non-empty string.', $key));
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function optionalString(array $args, string $key): ?string
    {
        if (!array_key_exists($key, $args) || null === $args[$key]) {
            return null;
        }
        if (!is_string($args[$key])) {
            throw McpException::invalidInput(sprintf('Field "%s" must be a string.', $key));
        }
        return $args[$key];
    }

    /**
     * @param array<string, mixed> $args
     */
    public function optionalInt(array $args, string $key): ?int
    {
        if (!array_key_exists($key, $args) || null === $args[$key]) {
            return null;
        }
        $value = $args[$key];
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw McpException::invalidInput(sprintf('Field "%s" must be an integer.', $key));
        }
        return (int) $value;
    }

    /**
     * @param array<string, mixed> $args
     * @return string[]|null
     */
    public function optionalStringArray(array $args, string $key): ?array
    {
        if (!array_key_exists($key, $args) || null === $args[$key]) {
            return null;
        }
        $value = $args[$key];
        if (!is_array($value)) {
            throw McpException::invalidInput(sprintf('Field "%s" must be an array of strings.', $key));
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || '' === $item) {
                throw McpException::invalidInput(sprintf('Field "%s" must contain non-empty strings.', $key));
            }
            $result[] = $item;
        }
        return $result;
    }

    /**
     * UUIDs reach the API as strings; this normalises and rejects malformed
     * IDs early so tools can assume a well-shaped {@see Uuid}.
     */
    public function requireUuid(string $field, mixed $raw): Uuid
    {
        if (!is_string($raw) || !Uuid::isValid($raw)) {
            throw McpException::invalidInput(sprintf('Field "%s" must be a valid UUID.', $field));
        }
        return Uuid::fromString($raw);
    }

    public function optionalUuid(string $field, mixed $raw): ?Uuid
    {
        if (null === $raw) {
            return null;
        }
        return $this->requireUuid($field, $raw);
    }

    /**
     * Accepts ISO-8601 strings (with or without timezone). Returns null
     * when the field is absent. Tools that want to clear a date pass an
     * explicit empty string, which we map to a sentinel via the
     * 3-arg overload below.
     */
    public function optionalDateTime(array $args, string $key): ?\DateTimeImmutable
    {
        if (!array_key_exists($key, $args) || null === $args[$key]) {
            return null;
        }
        $raw = $args[$key];
        if (!is_string($raw) || '' === $raw) {
            throw McpException::invalidInput(sprintf('Field "%s" must be an ISO-8601 datetime string.', $key));
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw McpException::invalidInput(sprintf('Field "%s" is not a valid datetime ("%s").', $key, $raw));
        }
    }

    /**
     * Run Symfony validator against an entity; turn the violation list
     * into a single 422 with the human-readable messages joined.
     */
    public function assertValid(object $entity): void
    {
        $violations = $this->validator->validate($entity);
        if (count($violations) > 0) {
            throw new McpException($this->violationsToMessage($violations), 422);
        }
    }

    public function violationsToMessage(ConstraintViolationListInterface $violations): string
    {
        $parts = [];
        foreach ($violations as $v) {
            $parts[] = trim(($v->getPropertyPath() !== '' ? $v->getPropertyPath() . ': ' : '') . $v->getMessage());
        }
        return implode('; ', $parts);
    }

    /**
     * Page/limit clamping shared by every list tool. Defaults match the
     * API Platform global default (30 per page).
     *
     * @return array{page: int, limit: int}
     */
    public function pagination(array $args, int $maxLimit = 100): array
    {
        $page = $this->optionalInt($args, 'page') ?? 1;
        $limit = $this->optionalInt($args, 'limit') ?? 30;
        if ($page < 1) {
            $page = 1;
        }
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > $maxLimit) {
            $limit = $maxLimit;
        }
        return ['page' => $page, 'limit' => $limit];
    }
}
