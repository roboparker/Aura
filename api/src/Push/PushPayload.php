<?php

namespace App\Push;

/**
 * Payload shape that the service-worker `push` event handler reads to build
 * the `Notification` it shows to the user. Kept JSON-serialisable so the
 * sender can hand it straight to minishlink/web-push as the encrypted body.
 */
final class PushPayload implements \JsonSerializable
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url = null,
        public readonly ?string $tag = null,
    ) {
    }

    /**
     * @return array<string, string|null>
     */
    public function jsonSerialize(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'tag' => $this->tag,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }
}
