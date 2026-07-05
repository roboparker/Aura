<?php

namespace App\Tests\CustomField;

use App\CustomField\Type\Boolean\BooleanStrategy;
use App\CustomField\Type\Date\DateStrategy;
use App\CustomField\Type\Date\DateTimeStrategy;
use App\CustomField\Type\Date\TimeStrategy;
use App\CustomField\Type\Numeric\FloatStrategy;
use App\CustomField\Type\Numeric\IntStrategy;
use App\CustomField\Type\Numeric\MoneyStrategy;
use App\CustomField\Type\Select\MultiSelectStrategy;
use App\CustomField\Type\Select\SingleSelectStrategy;
use App\CustomField\Type\Text\RichTextStrategy;
use App\CustomField\Type\Text\TextStrategy;
use App\CustomField\Type\Text\UrlStrategy;
use App\Entity\CustomFieldDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Per-strategy unit tests (#227). Exercise each strategy in isolation
 * — no kernel, no container, no DB — to keep the feedback loop tight
 * and prove every (kind, subtype) pair behaves consistently.
 *
 * Reference strategies need an EntityManager for scope checks; they
 * stay covered indirectly by ValidCustomFieldValuesValidator's
 * integration tests in TaskCustomFieldValueTest.
 */
final class TypeStrategiesTest extends TestCase
{
    public function testBoolean(): void
    {
        $s = new BooleanStrategy();
        $def = new CustomFieldDefinition();

        $this->assertSame('boolean.boolean', $s->key());
        $this->assertFalse($s->supportsMulti());
        $this->assertSame(['count'], $s->supportedAggregations());

        $this->assertCount(0, $s->validateValue(true, [], $def));
        $this->assertCount(0, $s->validateValue(false, [], $def));
        $this->assertCount(1, $s->validateValue('yes', [], $def));
        $this->assertCount(1, $s->validateValue(1, [], $def));
    }

    public function testTextLengthAndPattern(): void
    {
        $s = new TextStrategy();
        $def = new CustomFieldDefinition();

        $this->assertCount(0, $s->validateValue('hello', ['multi' => false], $def));
        $this->assertCount(1, $s->validateValue(42, ['multi' => false], $def));

        $config = ['minLength' => 3, 'maxLength' => 5, 'pattern' => '^[a-z]+$'];
        $this->assertCount(0, $s->validateValue('abc', $config, $def));
        $this->assertCount(1, $s->validateValue('ab', $config, $def));      // too short
        $this->assertCount(1, $s->validateValue('abcdef', $config, $def));  // too long
        $this->assertCount(1, $s->validateValue('ABC', $config, $def));     // pattern miss

        // Multi flag walks each element separately.
        $multi = ['multi' => true];
        $this->assertCount(0, $s->validateValue(['a', 'b'], $multi, $def));
        $this->assertCount(1, $s->validateValue(['a', 1], $multi, $def));
        // Non-list under multi gets a single top-level violation.
        $this->assertCount(1, $s->validateValue('a', $multi, $def));

        // Bad regex in config rejected on validateConfig, not at value time.
        $badConfig = ['pattern' => '['];
        $this->assertNotEmpty($s->validateConfig($badConfig));
    }

    public function testRichTextSubtypeKey(): void
    {
        $s = new RichTextStrategy();
        $this->assertSame('text.rich_text', $s->key());
        // Same validation rules — covered by parent. Sanity-check the
        // search boardion so a rich-text field's body still
        // contributes searchable tokens.
        $this->assertSame('hello', $s->searchText(' hello ', ['multi' => false]));
    }

    public function testUrlStrategyValidatesSchemeAndForm(): void
    {
        $s = new UrlStrategy();
        $def = new CustomFieldDefinition();

        $this->assertCount(0, $s->validateValue('https://example.com', [], $def));
        $this->assertCount(0, $s->validateValue('mailto:alice@example.com', [], $def));
        $this->assertCount(1, $s->validateValue('ftp://example.com', [], $def));
        $this->assertCount(1, $s->validateValue('not a url', [], $def));
    }

    public function testIntStrategyRejectsFloatsAndOutOfRange(): void
    {
        $s = new IntStrategy();
        $def = new CustomFieldDefinition();

        $this->assertCount(0, $s->validateValue(42, ['multi' => false], $def));
        $this->assertCount(1, $s->validateValue(42.5, ['multi' => false], $def));
        $this->assertCount(1, $s->validateValue(-5, ['multi' => false, 'min' => 0], $def));
        $this->assertCount(1, $s->validateValue(100, ['multi' => false, 'max' => 99], $def));

        // decimalPlaces is illegal for int fields.
        $this->assertNotEmpty($s->validateConfig(['decimalPlaces' => 2]));
    }

    public function testFloatStrategyNormalizesIntToFloat(): void
    {
        $s = new FloatStrategy();
        $this->assertSame(3.0, $s->normalize(3, ['multi' => false]));
        $this->assertSame(3.5, $s->normalize(3.5, ['multi' => false]));
        $this->assertSame([1.0, 2.5], $s->normalize([1, 2.5], ['multi' => true]));
    }

    public function testMoneyShapeAndCurrencyPin(): void
    {
        $s = new MoneyStrategy();
        $def = new CustomFieldDefinition();

        $this->assertFalse($s->supportsMulti());
        $this->assertNotEmpty($s->validateConfig(['currency' => 'XXX']));
        $this->assertEmpty($s->validateConfig(['currency' => 'USD']));

        $config = ['currency' => 'USD'];
        $this->assertCount(0, $s->validateValue(['amount' => 100, 'currency' => 'USD'], $config, $def));
        // Cross-currency value with a pinned field currency rejected.
        $this->assertNotEmpty($s->validateValue(['amount' => 100, 'currency' => 'EUR'], $config, $def));
        // Non-integer amount rejected.
        $this->assertNotEmpty($s->validateValue(['amount' => 1.5, 'currency' => 'USD'], $config, $def));

        $this->assertSame(
            ['amount' => 1000, 'currency' => 'USD'],
            $s->normalize(['amount' => '1000', 'currency' => 'usd'], $config),
        );
        $this->assertSame('1000 USD', $s->searchText(['amount' => 1000, 'currency' => 'USD'], $config));
    }

    public function testDateStrategiesParseStrict(): void
    {
        $date = new DateStrategy();
        $time = new TimeStrategy();
        $datetime = new DateTimeStrategy();
        $def = new CustomFieldDefinition();

        $this->assertCount(0, $date->validateValue('2026-05-12', [], $def));
        $this->assertCount(1, $date->validateValue('05/12/2026', [], $def));

        $this->assertCount(0, $time->validateValue('09:30', [], $def));
        $this->assertCount(1, $time->validateValue('9:30', [], $def));     // strict HH:MM
        $this->assertCount(1, $time->validateValue('25:00', [], $def));    // invalid hour

        $this->assertCount(0, $datetime->validateValue('2026-05-12T14:30:00+02:00', [], $def));
        // Tolerate JS toISOString shape ("Z" + fractional seconds).
        $this->assertCount(0, $datetime->validateValue('2026-05-12T14:30:00.000Z', [], $def));
        $this->assertCount(1, $datetime->validateValue('2026-05-12 14:30:00', [], $def));

        // Range bounds: in-format min/max are honoured.
        $config = ['min' => '2026-01-01', 'max' => '2026-12-31'];
        $this->assertCount(0, $date->validateValue('2026-06-15', $config, $def));
        $this->assertCount(1, $date->validateValue('2025-12-31', $config, $def));
    }

    public function testSingleSelectMembershipAndSearchText(): void
    {
        $s = new SingleSelectStrategy();
        $def = new CustomFieldDefinition();
        $config = [
            'multi' => false,
            'options' => [
                ['key' => 'low', 'label' => 'Low priority'],
                ['key' => 'hi', 'label' => 'High priority'],
            ],
        ];

        $this->assertCount(0, $s->validateValue('low', $config, $def));
        $this->assertCount(1, $s->validateValue('mid', $config, $def));
        $this->assertCount(1, $s->validateValue(['low'], $config, $def)); // not a list

        // searchText boards the user-facing label, not the key.
        $this->assertSame('Low priority', $s->searchText('low', $config));

        // Config: options required, no duplicate keys, color must be hex.
        $this->assertNotEmpty($s->validateConfig(['options' => []]));
        $this->assertNotEmpty($s->validateConfig([
            'options' => [['key' => 'k', 'label' => 'L'], ['key' => 'k', 'label' => 'L2']],
        ]));
        $this->assertNotEmpty($s->validateConfig([
            'options' => [['key' => 'k', 'label' => 'L', 'color' => 'red']],
        ]));
    }

    public function testMultiSelectListShapeAndDuplicates(): void
    {
        $s = new MultiSelectStrategy();
        $def = new CustomFieldDefinition();
        $config = [
            'multi' => true,
            'options' => [
                ['key' => 'a', 'label' => 'A'],
                ['key' => 'b', 'label' => 'B'],
            ],
        ];

        $this->assertCount(0, $s->validateValue(['a'], $config, $def));
        $this->assertCount(0, $s->validateValue(['a', 'b'], $config, $def));
        $this->assertCount(1, $s->validateValue('a', $config, $def));            // not a list
        $this->assertCount(1, $s->validateValue(['a', 'a'], $config, $def));     // duplicate
        $this->assertCount(1, $s->validateValue(['a', 'c'], $config, $def));     // unknown
        $this->assertCount(2, $s->validateValue([1, 'c'], $config, $def));       // non-string + unknown

        $this->assertSame('A B', $s->searchText(['a', 'b'], $config));
    }
}
