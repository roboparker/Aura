<?php

namespace App\Tests\CustomField;

use App\CustomField\Footer\FooterKind;
use App\CustomField\Type\Numeric\MoneyStrategy;
use App\Entity\CustomFieldDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for numeric.money (#227). Direct construction — no kernel,
 * no DB. Complements TypeStrategiesTest's happy-path checks with the
 * config branches, the min/max amount bounds, and the search/normalize
 * edge cases.
 */
final class MoneyStrategyTest extends TestCase
{
    public function testKeyAndCapabilities(): void
    {
        $s = new MoneyStrategy();

        $this->assertSame('numeric.money', $s->key());
        $this->assertFalse($s->supportsMulti());
        $this->assertSame(
            [
                FooterKind::SUM->value,
                FooterKind::AVG->value,
                FooterKind::MIN->value,
                FooterKind::MAX->value,
                FooterKind::COUNT->value,
            ],
            $s->supportedAggregations(),
        );
    }

    public function testConfigValidation(): void
    {
        $s = new MoneyStrategy();

        // Currency required + must be known.
        $this->assertNotEmpty($s->validateConfig([]));
        $this->assertNotEmpty($s->validateConfig(['currency' => '']));
        $this->assertNotEmpty($s->validateConfig(['currency' => 123]));
        $this->assertNotEmpty($s->validateConfig(['currency' => 'XXX']));
        $this->assertEmpty($s->validateConfig(['currency' => 'USD']));
        // Lower-case is accepted (uppercased before the existence check).
        $this->assertEmpty($s->validateConfig(['currency' => 'usd']));

        // min/max must be integers (minor units).
        $this->assertNotEmpty($s->validateConfig(['currency' => 'USD', 'min' => 1.5]));
        $this->assertNotEmpty($s->validateConfig(['currency' => 'USD', 'max' => 'x']));
        // min > max.
        $this->assertNotEmpty($s->validateConfig(['currency' => 'USD', 'min' => 100, 'max' => 10]));
        $this->assertEmpty($s->validateConfig(['currency' => 'USD', 'min' => 10, 'max' => 100]));
    }

    public function testValueShapeAndCurrencyPin(): void
    {
        $s = new MoneyStrategy();
        $def = new CustomFieldDefinition();
        $config = ['currency' => 'USD'];

        $this->assertCount(0, $s->validateValue(['amount' => 100, 'currency' => 'USD'], $config, $def));
        // Non-array value.
        $this->assertCount(1, $s->validateValue('100', $config, $def));
        // Non-integer amount.
        $this->assertNotEmpty($s->validateValue(['amount' => 1.5, 'currency' => 'USD'], $config, $def));
        // Missing currency.
        $this->assertNotEmpty($s->validateValue(['amount' => 100], $config, $def));
        // Unknown currency code.
        $this->assertNotEmpty($s->validateValue(['amount' => 100, 'currency' => 'XXX'], $config, $def));
        // Mismatch against the pinned field currency.
        $this->assertNotEmpty($s->validateValue(['amount' => 100, 'currency' => 'EUR'], $config, $def));
    }

    public function testAmountBounds(): void
    {
        $s = new MoneyStrategy();
        $def = new CustomFieldDefinition();
        $config = ['currency' => 'USD', 'min' => 0, 'max' => 1000];

        $this->assertCount(0, $s->validateValue(['amount' => 500, 'currency' => 'USD'], $config, $def));
        $this->assertNotEmpty($s->validateValue(['amount' => -1, 'currency' => 'USD'], $config, $def));
        $this->assertNotEmpty($s->validateValue(['amount' => 1001, 'currency' => 'USD'], $config, $def));
    }

    public function testNormalize(): void
    {
        $s = new MoneyStrategy();
        $config = ['currency' => 'USD'];

        $this->assertSame(
            ['amount' => 1000, 'currency' => 'USD'],
            $s->normalize(['amount' => '1000', 'currency' => 'usd'], $config),
        );
        // Non-array passes through unchanged.
        $this->assertSame('x', $s->normalize('x', $config));
        // Non-numeric amount + non-string currency are left as-is.
        $this->assertSame(
            ['amount' => null, 'currency' => 5],
            $s->normalize(['amount' => null, 'currency' => 5], $config),
        );
    }

    public function testSearchText(): void
    {
        $s = new MoneyStrategy();
        $config = ['currency' => 'USD'];

        $this->assertSame('1000 USD', $s->searchText(['amount' => 1000, 'currency' => 'USD'], $config));
        // Non-array -> null.
        $this->assertNull($s->searchText('1000', $config));
        // Malformed shape -> null.
        $this->assertNull($s->searchText(['amount' => 'x', 'currency' => 'USD'], $config));
        $this->assertNull($s->searchText(['amount' => 1000, 'currency' => 5], $config));
    }
}
