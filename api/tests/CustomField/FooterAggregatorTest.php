<?php

namespace App\Tests\CustomField;

use App\CustomField\CustomFieldTypeRegistry;
use App\CustomField\Footer\FooterAggregator;
use App\CustomField\Footer\FooterKind;
use App\CustomField\Type\Boolean\BooleanStrategy;
use App\CustomField\Type\Date\DateStrategy;
use App\CustomField\Type\Numeric\FloatStrategy;
use App\CustomField\Type\Numeric\IntStrategy;
use App\CustomField\Type\Numeric\MoneyStrategy;
use App\CustomField\Type\Select\SingleSelectStrategy;
use App\Entity\CustomFieldDefinition;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for FooterAggregator's row-assembly + dispatch logic (#227).
 *
 * The SQL aggregation paths (sum/avg/min/max over custom_field_value)
 * are integration-tested against a live Postgres in the API suite. Here
 * we cover the parts that run without ever issuing a query:
 *   - skip rows with no footer / an invalid footer.kind / an aggregation
 *     the strategy doesn't support;
 *   - the empty-task-set short-circuit in compute() (count -> 0,
 *     everything else -> null).
 *
 * The {@see Connection} is mocked and must never be touched — passing an
 * empty task-id list keeps every path off the database.
 */
final class FooterAggregatorTest extends TestCase
{
    private function registry(): CustomFieldTypeRegistry
    {
        return new CustomFieldTypeRegistry([
            new BooleanStrategy(),
            new IntStrategy(),
            new FloatStrategy(),
            new MoneyStrategy(),
            new DateStrategy(),
            new SingleSelectStrategy(),
        ]);
    }

    private function aggregator(Connection $connection): FooterAggregator
    {
        return new FooterAggregator($connection, $this->registry());
    }

    /**
     * @param array{kind: string, label?: string}|null $footer
     */
    private function definition(string $kind, string $subtype, ?array $footer): CustomFieldDefinition
    {
        $def = new CustomFieldDefinition();
        $def->setKind($kind);
        $def->setSubtype($subtype);
        if (null !== $footer) {
            $def->setFooter($footer);
        }
        return $def;
    }

    public function testSkipsDefinitionsWithoutAFooter(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeQuery');

        $rows = $this->aggregator($connection)->aggregate(
            [$this->definition('numeric', 'int', null)],
            [],
        );

        $this->assertSame([], $rows);
    }

    public function testSkipsInvalidFooterKind(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeQuery');

        $rows = $this->aggregator($connection)->aggregate(
            [$this->definition('numeric', 'int', ['kind' => 'bogus'])],
            [],
        );

        $this->assertSame([], $rows);
    }

    public function testSkipsAggregationNotSupportedByStrategy(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeQuery');

        // Booleans only support COUNT — asking for SUM is dropped.
        $rows = $this->aggregator($connection)->aggregate(
            [$this->definition('boolean', 'boolean', ['kind' => FooterKind::SUM->value])],
            [],
        );

        $this->assertSame([], $rows);
    }

    public function testSkipsUnknownTypeKey(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeQuery');

        // 'numeric.decimal' isn't a registered strategy -> dropped.
        $rows = $this->aggregator($connection)->aggregate(
            [$this->definition('numeric', 'decimal', ['kind' => FooterKind::SUM->value])],
            [],
        );

        $this->assertSame([], $rows);
    }

    public function testEmptyTaskSetCountIsZero(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeQuery');

        $rows = $this->aggregator($connection)->aggregate(
            [$this->definition('numeric', 'int', ['kind' => FooterKind::COUNT->value, 'label' => 'Filled'])],
            [],
        );

        $this->assertCount(1, $rows);
        $this->assertSame(FooterKind::COUNT->value, $rows[0]['kind']);
        $this->assertSame('Filled', $rows[0]['label']);
        $this->assertSame(0, $rows[0]['value']);
    }

    public function testEmptyTaskSetNonCountIsNull(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeQuery');

        $rows = $this->aggregator($connection)->aggregate(
            [
                $this->definition('numeric', 'int', ['kind' => FooterKind::SUM->value]),
                $this->definition('numeric', 'float', ['kind' => FooterKind::AVG->value]),
                $this->definition('numeric', 'money', ['kind' => FooterKind::MAX->value]),
                $this->definition('date', 'date', ['kind' => FooterKind::MIN->value]),
            ],
            [],
        );

        $this->assertCount(4, $rows);
        foreach ($rows as $row) {
            $this->assertNull($row['value']);
        }
    }

    public function testSelectBreakdownEmptyTaskSetReturnsZeroFilledOptions(): void
    {
        $connection = $this->createMock(Connection::class);
        // Breakdown short-circuits to zero counts without querying when the
        // task scope is empty.
        $connection->expects($this->never())->method('executeQuery');

        $def = $this->definition('select', 'single', ['kind' => FooterKind::BREAKDOWN->value]);
        $def->setConfig([
            'options' => [
                ['key' => 'backlog', 'label' => 'Backlog'],
                ['key' => 'done', 'label' => 'Done'],
            ],
        ]);

        $rows = $this->aggregator($connection)->aggregate([$def], []);

        $this->assertCount(1, $rows);
        $this->assertSame(FooterKind::BREAKDOWN->value, $rows[0]['kind']);
        $this->assertSame(
            [
                ['key' => 'backlog', 'label' => 'Backlog', 'count' => 0],
                ['key' => 'done', 'label' => 'Done', 'count' => 0],
            ],
            $rows[0]['value'],
        );
    }

    public function testMissingLabelIsNull(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeQuery');

        $rows = $this->aggregator($connection)->aggregate(
            [$this->definition('numeric', 'int', ['kind' => FooterKind::COUNT->value])],
            [],
        );

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['label']);
    }
}
