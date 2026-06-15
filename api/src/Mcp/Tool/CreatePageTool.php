<?php

namespace App\Mcp\Tool;

use App\Entity\Page;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Mcp\McpSpaceResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates a long-form, Notion-style page in a space (#183). The author
 * is stamped server-side; pass a parentId to nest it under another page.
 */
final class CreatePageTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpAuthorization $authz,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
        private McpSpaceResolver $spaces,
    ) {
    }

    public function getName(): string
    {
        return 'create_page';
    }

    public function getDescription(): string
    {
        return 'Create a long-form Markdown page (Notion-style doc) in a space. Pass a spaceId (from list_spaces) for a shared space, or omit it for your personal space. Pass a parentId to nest it under an existing page in the same space.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Page title (required).'],
                'body' => ['type' => 'string', 'description' => 'Markdown body.'],
                'spaceId' => ['type' => 'string', 'description' => 'UUID of a space the user belongs to. Omit for the personal space.'],
                'parentId' => ['type' => 'string', 'description' => 'UUID of a parent page in the same space, to nest this one beneath it.'],
                'position' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Sort key within the parent (default 0).'],
            ],
            'required' => ['title'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $title = $this->input->requireString($arguments, 'title');
        $body = $this->input->optionalString($arguments, 'body');
        $position = $this->input->optionalInt($arguments, 'position');
        $space = $this->spaces->resolveMemberSpace($arguments['spaceId'] ?? null, $user);

        $page = new Page();
        $page->setCreatedBy($user);
        $page->setSpace($space);
        $page->setTitle($title);
        if (null !== $body) {
            $page->setBody($body);
        }
        if (null !== $position) {
            $page->setPosition($position);
        }

        $parentId = $this->input->optionalUuid('parentId', $arguments['parentId'] ?? null);
        if (null !== $parentId) {
            $parent = $this->em->getRepository(Page::class)->find($parentId);
            if (null === $parent || !$this->authz->canReadPage($parent, $user)) {
                throw McpException::notFound(sprintf('Page %s', $parentId));
            }
            if ($parent->getSpace()?->getId()?->equals($space->getId()) !== true) {
                throw McpException::invalidInput('The parent page must be in the same space.');
            }
            $page->setParent($parent);
        }

        $this->input->assertValid($page);
        $this->em->persist($page);
        $this->em->flush();

        return $this->serializer->page($page);
    }
}
