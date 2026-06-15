<?php

namespace App\Mcp\Tool;

use App\Entity\Page;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

final class GetPageTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpAuthorization $authz,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'get_page';
    }

    public function getDescription(): string
    {
        return 'Fetch one page by id, including its full Markdown body. Returns 404 when the user is not a member of the page\'s space.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => ['type' => 'string', 'description' => 'UUID of the page.'],
            ],
            'required' => ['pageId'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $pageId = $this->input->requireUuid('pageId', $arguments['pageId'] ?? null);
        $page = $this->em->getRepository(Page::class)->find($pageId);
        if (null === $page || !$this->authz->canReadPage($page, $user)) {
            throw McpException::notFound(sprintf('Page %s', $pageId));
        }

        return $this->serializer->page($page);
    }
}
