<?php

namespace App\Tests\CustomField;

use App\CustomField\Converter\ConversionContext;
use App\CustomField\Converter\DateValueConverter;
use App\CustomField\Converter\SelectValueConverter;
use App\CustomField\Converter\ValueCoercions;
use PHPUnit\Framework\TestCase;

/**
 * Custom-field value converters (#227): date and select target shaping.
 */
class ValueConvertersTest extends TestCase
{
    private ValueCoercions $coercions;

    protected function setUp(): void
    {
        $this->coercions = new ValueCoercions();
    }

    /**
     * @param array<string, mixed> $fromConfig
     * @param array<string, mixed> $toConfig
     */
    private function ctx(
        string $fromKind,
        string $fromSubtype,
        string $toSubtype,
        array $fromConfig = [],
        array $toConfig = [],
    ): ConversionContext {
        return new ConversionContext($fromKind, $fromSubtype, $fromConfig, $toSubtype, $toConfig);
    }

    public function testDateTargetKind(): void
    {
        $this->assertSame('date', (new DateValueConverter($this->coercions))->targetKind());
    }

    public function testDateSameSubtype(): void
    {
        $converter = new DateValueConverter($this->coercions);
        $this->assertSame([true, '2026-08-15'], $converter->convert('2026-08-15', $this->ctx('date', 'date', 'date')));
    }

    public function testDateToDatetime(): void
    {
        $converter = new DateValueConverter($this->coercions);
        [$ok, $out] = $converter->convert('2026-08-15', $this->ctx('date', 'date', 'datetime'));
        $this->assertTrue($ok);
        $this->assertIsString($out);
        $this->assertStringStartsWith('2026-08-15T00:00:00', $out);
    }

    public function testDateTimeCrossingsFail(): void
    {
        $converter = new DateValueConverter($this->coercions);
        // time → date is meaningless.
        $this->assertSame([false, null], $converter->convert('14:30', $this->ctx('date', 'time', 'date')));
        // date → time is meaningless.
        $this->assertSame([false, null], $converter->convert('2026-08-15', $this->ctx('date', 'date', 'time')));
    }

    public function testDateFromUnsupportedKindFails(): void
    {
        $converter = new DateValueConverter($this->coercions);
        $this->assertSame([false, null], $converter->convert('x', $this->ctx('numeric', 'integer', 'date')));
    }

    public function testDateFromTextLenient(): void
    {
        $converter = new DateValueConverter($this->coercions);
        [$ok, $out] = $converter->convert('2 Jan 2026', $this->ctx('text', 'text', 'date'));
        $this->assertTrue($ok);
        $this->assertSame('2026-01-02', $out);
        $this->assertSame([false, null], $converter->convert('nonsense!!', $this->ctx('text', 'text', 'date')));
    }

    public function testSelectTargetKind(): void
    {
        $this->assertSame('select', (new SelectValueConverter($this->coercions))->targetKind());
    }

    public function testSelectKeyPassesThrough(): void
    {
        $converter = new SelectValueConverter($this->coercions);
        $to = ['options' => [['key' => 'a', 'label' => 'Alpha']]];
        $this->assertSame([true, 'a'], $converter->convert('a', $this->ctx('select', 'single', 'single', [], $to)));
    }

    public function testSelectBridgesByLabel(): void
    {
        $converter = new SelectValueConverter($this->coercions);
        $from = ['options' => [['key' => 'old', 'label' => 'Alpha']]];
        $to = ['options' => [['key' => 'new', 'label' => 'Alpha']]];
        $this->assertSame([true, 'new'], $converter->convert('old', $this->ctx('select', 'single', 'single', $from, $to)));
        // No label match → fail.
        $this->assertSame([false, null], $converter->convert('old', $this->ctx('select', 'single', 'single', $from, ['options' => []])));
    }

    public function testSelectFromText(): void
    {
        $converter = new SelectValueConverter($this->coercions);
        $to = ['options' => [['key' => 'a', 'label' => 'Alpha']]];
        // Matches a key.
        $this->assertSame([true, 'a'], $converter->convert('a', $this->ctx('text', 'text', 'single', [], $to)));
        // Matches a label.
        $this->assertSame([true, 'a'], $converter->convert('Alpha', $this->ctx('text', 'text', 'single', [], $to)));
        // No match.
        $this->assertSame([false, null], $converter->convert('zzz', $this->ctx('text', 'text', 'single', [], $to)));
    }

    public function testSelectNonStringFails(): void
    {
        $converter = new SelectValueConverter($this->coercions);
        $this->assertSame([false, null], $converter->convert(5, $this->ctx('select', 'single', 'single')));
    }
}
