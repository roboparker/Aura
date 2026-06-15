<?php

namespace App\Mcp\Tool;

use App\Entity\Page;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Patches a page. Editable by the author or a space admin, mirroring the
 * REST `Patch` security expression. The page's space is fixed here — use
 * the REST move endpoint to relocate a page between spaces.
 */
final class UpdatePageTool implements McpToolInterface
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
        return 'update_page';
    }

    public function getDescription(): string
    {
        return 'Patch one or more fields on a page (title, body, position, parent). Only fields included in the call are changed. Editable by the page author or a space admin.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => ['type' => 'string', 'description' => 'UUID of the page.'],
                'title' => ['type' => 'string'],
                'body' => ['type' => 'string'],
                'position' => ['type' => 'integer', 'minimum' => 0],
                'parentId' => ['type' => ['string', 'null'], 'description' => 'Re-nest under another page in the same space, or null to make it top-level.'],
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
            throw McpException::forbidden('Only the page author or a space admin can edit this page.');
        }

        if (array_key_exists('title', $arguments)) {
            $page->setTitle($this->input->requireString($arguments, 'title'));
        }
        if (array_key_exists('body', $arguments)) {
            $page->setBody($this->input->optionalString($arguments, 'body') ?? '');
        }
        if (array_key_exists('position', $arguments)) {
            $page->setPosition($this->input->optionalInt($arguments, 'position') ?? 0);
        }
        if (array_key_exists('parentId', $arguments)) {
            if (null === $arguments['parentId']) {
                $page->setParent(null);
            } else {
                $parentId = $this->input->requireUuid('parentId', $arguments['parentId']);
                if ($parentId->equals($pageId)) {
                    throw McpException::invalidInput('A page cannot be its own parent.');
                }
                $parent = $this->em->getRepository(Page::class)->find($parentId);
                if (null === $parent || !$this->authz->canReadPage($parent, $user)) {
                    throw McpException::notFound(sprintf('Page %s', $parentId));
                }
                if ($parent->getSpace()?->getId()?->equals($page->getSpace()?->getId()) !== true) {
                    throw McpException::invalidInput('The parent page must be in the same space.');
                }
                $page->setParent($parent);
            }
        }

        $this->input->assertValid($page);
        $this->em->flush();

        return $this->serializer->page($page);
    }
}
