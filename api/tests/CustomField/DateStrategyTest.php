<?php

namespace App\Tests\CustomField;

use App\CustomField\Footer\FooterKind;
use App\CustomField\Type\Date\DateStrategy;
use App\CustomField\Type\Date\DateTimeStrategy;
use App\CustomField\Type\Date\TimeStrategy;
use App\Entity\CustomFieldDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the date-flavoured strategies and their shared abstract
 * base (#227). Direct construction — no kernel, no DB. Complements
 * TypeStrategiesTest by exercising the multi walk, config bounds, the
 * search-text projection, and the per-subtype scalar overrides.
 */
final class DateStrategyTest extends TestCase
{
    public function testKeysAndCapabilities(): void
    {
        $date = new DateStrategy();
        $time = new TimeStrategy();
        $datetime = new DateTimeStrategy();

        $this->assertSame('date.date', $date->key());
        $this->assertSame('date.time', $time->key());
        $this->assertSame('date.datetime', $datetime->key());

        foreach ([$date, $time, $datetime] as $s) {
            $this->assertTrue($s->supportsMulti());
            $this->assertSame(
                [FooterKind::MIN->value, FooterKind::MAX->value, FooterKind::COUNT->value],
                $s->supportedAggregations(),
            );
        }
    }

    public function testDateRangeBounds(): void
    {
        $date = new DateStrategy();
        $def = new CustomFieldDefinition();

        $config = ['min' => '2026-01-01', 'max' => '2026-12-31'];
        $this->assertCount(0, $date->validateValue('2026-06-15', $config, $def));
        $this->assertCount(1, $date->validateValue('2025-12-31', $config, $def)); // before min
        $this->assertCount(1, $date->validateValue('2027-01-01', $config, $def)); // after max
        // Calendar overflow is rejected by the round-trip parse guard.
        $this->assertCount(1, $date->validateValue('2026-02-30', [], $def));
        // Non-string scalar.
        $this->assertCount(1, $date->validateValue(20260615, [], $def));
    }

    public function testDateConfigValidation(): void
    {
        $date = new DateStrategy();

        $this->assertEmpty($date->validateConfig([]));
        $this->assertEmpty($date->validateConfig(['min' => '2026-01-01', 'max' => '2026-12-31']));
        // Unparseable bound.
        $this->assertNotEmpty($date->validateConfig(['min' => 'not-a-date']));
        // Non-string bound.
        $this->assertNotEmpty($date->validateConfig(['max' => 123]));
        // min after max.
        $this->assertNotEmpty($date->validateConfig(['min' => '2026-12-31', 'max' => '2026-01-01']));
        // multi flag is honoured by the shared multi-config check.
        $this->assertEmpty($date->validateConfig(['multi' => true]));
        $this->assertNotEmpty($date->validateConfig(['multi' => 'yes']));
    }

    public function testDateMultiWalk(): void
    {
        $date = new DateStrategy();
        $def = new CustomFieldDefinition();
        $multi = ['multi' => true];

        $this->assertCount(0, $date->validateValue(['2026-01-01', '2026-02-02'], $multi, $def));
        // One bad element -> one violation.
        $this->assertCount(1, $date->validateValue(['2026-01-01', 'nope'], $multi, $def));
        // Non-list under multi -> single top-level violation.
        $this->assertCount(1, $date->validateValue('2026-01-01', $multi, $def));
        // Associative map under multi -> shape violation.
        $this->assertCount(1, $date->validateValue(['a' => '2026-01-01'], $multi, $def));
    }

    public function testDateSearchText(): void
    {
        $date = new DateStrategy();

        $this->assertNull($date->searchText(null, []));
        $this->assertSame('2026-06-15', $date->searchText('2026-06-15', []));
        // Invalid string boards nothing.
        $this->assertNull($date->searchText('garbage', []));
        // Multi: only the parseable elements survive.
        $this->assertSame(
            '2026-01-01 2026-02-02',
            $date->searchText(['2026-01-01', 'bad', '2026-02-02'], ['multi' => true]),
        );
        $this->assertNull($date->searchText(['bad'], ['multi' => true]));
    }

    public function testTimeStrictFormatMessage(): void
    {
        $time = new TimeStrategy();
        $def = new CustomFieldDefinition();

        $this->assertCount(0, $time->validateValue('09:30', [], $def));
        // Missing leading zero gets the subtype-specific message branch.
        $this->assertCount(1, $time->validateValue('9:30', [], $def));
        // Out-of-range hour rejected by the round-trip parse.
        $this->assertCount(1, $time->validateValue('25:00', [], $def));
        // Non-string falls through to the abstract base path.
        $this->assertCount(1, $time->validateValue(930, [], $def));

        // In-range bound honoured.
        $config = ['min' => '08:00', 'max' => '17:00'];
        $this->assertCount(0, $time->validateValue('12:00', $config, $def));
        $this->assertCount(1, $time->validateValue('07:59', $config, $def));
    }

    public function testDateTimeIsoTolerance(): void
    {
        $datetime = new DateTimeStrategy();
        $def = new CustomFieldDefinition();

        $this->assertCount(0, $datetime->validateValue('2026-05-12T14:30:00+02:00', [], $def));
        // JS toISOString shape (Z + fractional seconds) tolerated.
        $this->assertCount(0, $datetime->validateValue('2026-05-12T14:30:00.000Z', [], $def));
        // Space separator (no offset) rejected.
        $this->assertCount(1, $datetime->validateValue('2026-05-12 14:30:00', [], $def));
        // Non-string falls through to the abstract base path.
        $this->assertCount(1, $datetime->validateValue(42, [], $def));
    }

    public function testDateTimeNormalize(): void
    {
        $datetime = new DateTimeStrategy();

        $this->assertNull($datetime->normalize(null, []));
        // Z + fractional seconds normalized to an explicit offset.
        $this->assertSame(
            '2026-05-12T14:30:00+00:00',
            $datetime->normalize('2026-05-12T14:30:00.000Z', []),
        );
        // Multi normalizes each element; non-strings pass through.
        $this->assertSame(
            ['2026-05-12T14:30:00+00:00', 5],
            $datetime->normalize(['2026-05-12T14:30:00.000Z', 5], ['multi' => true]),
        );
        // Multi with non-array input is returned unchanged.
        $this->assertSame('x', $datetime->normalize('x', ['multi' => true]));
    }
}
