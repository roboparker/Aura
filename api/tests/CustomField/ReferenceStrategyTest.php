<?php

namespace App\Tests\CustomField;

use App\CustomField\Footer\FooterKind;
use App\CustomField\Type\Reference\BoardReferenceStrategy;
use App\CustomField\Type\Reference\TaskReferenceStrategy;
use App\CustomField\Type\Reference\UserReferenceStrategy;
use App\Entity\CustomFieldDefinition;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the reference strategies' non-DB branches (#227).
 *
 * The DB-resolution path (IRI -> repository lookup -> space-scope check)
 * is exercised end-to-end by TaskCustomFieldValueTest's integration
 * suite, which needs a real EntityManager + fixtures. Here we cover
 * everything that runs *before* the repository is touched — key/kind,
 * capabilities, multi-shape rejection, non-string and bad-IRI scalars,
 * and the search-text null branches — with a mocked EntityManager that
 * is never invoked.
 */
final class ReferenceStrategyTest extends TestCase
{
    private function em(): EntityManagerInterface
    {
        return $this->createMock(EntityManagerInterface::class);
    }

    public function testKeysAndCapabilities(): void
    {
        $user = new UserReferenceStrategy($this->em());
        $task = new TaskReferenceStrategy($this->em());
        $board = new BoardReferenceStrategy($this->em());

        $this->assertSame('reference.user', $user->key());
        $this->assertSame('reference.task', $task->key());
        $this->assertSame('reference.board', $board->key());

        foreach ([$user, $task, $board] as $s) {
            $this->assertTrue($s->supportsMulti());
            // References don't aggregate beyond count.
            $this->assertSame([FooterKind::COUNT->value], $s->supportedAggregations());
        }
    }

    public function testRejectsNonStringScalar(): void
    {
        $user = new UserReferenceStrategy($this->em());
        $def = new CustomFieldDefinition(); // no space -> scope check skipped

        // Non-string value never reaches the repository.
        $this->assertCount(1, $user->validateValue(42, [], $def));
    }

    public function testRejectsMalformedIri(): void
    {
        $user = new UserReferenceStrategy($this->em());
        $def = new CustomFieldDefinition();

        // Wrong prefix -> extractUuid bails before any lookup.
        $this->assertCount(1, $user->validateValue('/tasks/00000000-0000-0000-0000-000000000000', [], $def));
        // Right prefix, non-UUID tail.
        $this->assertCount(1, $user->validateValue('/users/not-a-uuid', [], $def));
    }

    public function testMultiShapeRejection(): void
    {
        $user = new UserReferenceStrategy($this->em());
        $def = new CustomFieldDefinition();
        $multi = ['multi' => true];

        // Non-list under multi -> single shape violation, no lookup.
        $this->assertCount(1, $user->validateValue('/users/00000000-0000-0000-0000-000000000000', $multi, $def));
        // Associative map under multi -> shape violation.
        $this->assertCount(1, $user->validateValue(['k' => '/users/x'], $multi, $def));
    }

    public function testSearchTextNullBranches(): void
    {
        $user = new UserReferenceStrategy($this->em());

        $this->assertNull($user->searchText(null, []));
        // Non-string single value resolves to null without a lookup.
        $this->assertNull($user->searchText(42, []));
        // Malformed IRI -> labelFor returns null without a lookup.
        $this->assertNull($user->searchText('/tasks/abc', []));
        // Multi with no resolvable elements -> null.
        $this->assertNull($user->searchText([42, '/bad/iri'], ['multi' => true]));
    }
}
