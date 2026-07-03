<?php

namespace App\Tests\CustomField;

use App\CustomField\Converter\ValueCoercions;
use PHPUnit\Framework\TestCase;

/**
 * Source-scalar coercions shared by the custom-field value converters.
 */
class ValueCoercionsTest extends TestCase
{
    private ValueCoercions $coercions;

    protected function setUp(): void
    {
        $this->coercions = new ValueCoercions();
    }

    public function testToNumberFromNumeric(): void
    {
        $this->assertSame(3.0, $this->coercions->toNumber(3, 'numeric', 'integer', []));
        $this->assertSame(2.5, $this->coercions->toNumber(2.5, 'numeric', 'float', []));
        $this->assertNull($this->coercions->toNumber('x', 'numeric', 'integer', []));
    }

    public function testToNumberFromMoney(): void
    {
        // USD has 2 fraction digits → 1050 minor = 10.5 major.
        $this->assertSame(10.5, $this->coercions->toNumber(
            ['amount' => 1050, 'currency' => 'USD'],
            'numeric',
            'money',
            [],
        ));
        $this->assertNull($this->coercions->toNumber('nope', 'numeric', 'money', []));
        $this->assertNull($this->coercions->toNumber(['amount' => 'x', 'currency' => 'USD'], 'numeric', 'money', []));
    }

    public function testToNumberFromBooleanAndText(): void
    {
        $this->assertSame(1.0, $this->coercions->toNumber(true, 'boolean', 'boolean', []));
        $this->assertSame(0.0, $this->coercions->toNumber(false, 'boolean', 'boolean', []));
        $this->assertNull($this->coercions->toNumber('x', 'boolean', 'boolean', []));
        $this->assertSame(4.0, $this->coercions->toNumber(' 4 ', 'text', 'text', []));
        $this->assertNull($this->coercions->toNumber('abc', 'text', 'text', []));
        $this->assertNull($this->coercions->toNumber('x', 'date', 'date', []));
    }

    public function testStringify(): void
    {
        $this->assertSame('hi', $this->coercions->stringify('hi', 'text', 'text', []));
        $this->assertSame('Yes', $this->coercions->stringify(true, 'boolean', 'boolean', []));
        $this->assertSame('No', $this->coercions->stringify(false, 'boolean', 'boolean', []));
        $this->assertNull($this->coercions->stringify(1, 'boolean', 'boolean', []));
        $this->assertSame('42', $this->coercions->stringify(42, 'numeric', 'integer', []));
        $this->assertSame('2.5', $this->coercions->stringify(2.5, 'numeric', 'float', []));
        $this->assertSame('10.50 USD', $this->coercions->stringify(
            ['amount' => 1050, 'currency' => 'usd'],
            'numeric',
            'money',
            [],
        ));
        $this->assertNull($this->coercions->stringify('x', 'numeric', 'money', []));
    }

    public function testStringifySelectUsesLabel(): void
    {
        $config = ['options' => [['key' => 'a', 'label' => 'Alpha'], ['key' => 'b', 'label' => 'Beta']]];
        $this->assertSame('Alpha', $this->coercions->stringify('a', 'select', 'single', $config));
        // Unknown key falls back to the raw value.
        $this->assertSame('z', $this->coercions->stringify('z', 'select', 'single', $config));
        $this->assertNull($this->coercions->stringify(5, 'select', 'single', $config));
    }

    public function testParseDate(): void
    {
        $this->assertSame('2026-08-15', $this->coercions->parseDate('2026-08-15', 'date', 'date')?->format('Y-m-d'));
        $this->assertSame('14:30', $this->coercions->parseDate('14:30', 'date', 'time')?->format('H:i'));
        $this->assertNull($this->coercions->parseDate('not-a-date', 'date', 'date'));
        $this->assertNull($this->coercions->parseDate(5, 'date', 'date'));
        // Lenient text parsing.
        $this->assertSame('2026-01-02', $this->coercions->parseDate('2 Jan 2026', 'text', 'text')?->format('Y-m-d'));
        $this->assertNull($this->coercions->parseDate('gibberish!!', 'text', 'text'));
    }

    public function testFractionDigitsAndFloatToString(): void
    {
        $this->assertSame(2, $this->coercions->fractionDigits('USD'));
        $this->assertSame(0, $this->coercions->fractionDigits('JPY'));
        $this->assertSame(2, $this->coercions->fractionDigits('ZZZ')); // unknown → default 2
        $this->assertSame('0', $this->coercions->floatToString(0.0));
        $this->assertSame('1.25', $this->coercions->floatToString(1.25));
        $this->assertSame('3', $this->coercions->floatToString(3.0));
    }

    public function testSelectHelpers(): void
    {
        $config = ['options' => [['key' => 'a', 'label' => 'Alpha'], ['key' => 'b', 'label' => 'Beta'], 'junk']];
        $this->assertSame(['a', 'b'], $this->coercions->selectKeys($config));
        $this->assertSame(['a' => 'Alpha', 'b' => 'Beta'], $this->coercions->selectLabels($config));
        $this->assertSame('b', $this->coercions->keyForLabel($config, ' beta '));
        $this->assertNull($this->coercions->keyForLabel($config, 'nope'));
        $this->assertSame([], $this->coercions->selectKeys(['options' => 'bad']));
        $this->assertSame([], $this->coercions->selectLabels([]));
    }
}
