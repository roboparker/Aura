<?php

namespace App\Mcp\Tool;

use App\Entity\Page;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes a page. Author or space admin only. Descendants cascade away
 * via the self-FK `ON DELETE CASCADE`, matching the REST `Delete`.
 */
final class DeletePageTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpAuthorization $authz,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'delete_page';
    }

    public function getDescription(): string
    {
        return 'Delete a page by id. Deletes its sub-pages too. Allowed for the page author or a space admin.';
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
        if (!$this->authz->canEditPage($page, $user)) {
            throw McpException::forbidden('Only the page author or a space admin can delete this page.');
        }

        $this->em->remove($page);
        $this->em->flush();

        return ['deleted' => true, 'id' => (string) $pageId];
    }
}
