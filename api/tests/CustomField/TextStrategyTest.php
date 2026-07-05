<?php

namespace App\Tests\CustomField;

use App\CustomField\Footer\FooterKind;
use App\CustomField\Type\Text\RichTextStrategy;
use App\CustomField\Type\Text\TextStrategy;
use App\CustomField\Type\Text\UrlStrategy;
use App\Entity\CustomFieldDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the text-flavoured strategies and their shared abstract
 * base (#227). Direct construction — no kernel, no DB. Complements
 * TypeStrategiesTest by exercising config bounds, normalization, the
 * search projection, and the URL scheme override.
 */
final class TextStrategyTest extends TestCase
{
    public function testKeysAndCapabilities(): void
    {
        $text = new TextStrategy();
        $url = new UrlStrategy();
        $rich = new RichTextStrategy();

        $this->assertSame('text.text', $text->key());
        $this->assertSame('text.url', $url->key());
        $this->assertSame('text.rich_text', $rich->key());

        foreach ([$text, $url, $rich] as $s) {
            $this->assertTrue($s->supportsMulti());
            $this->assertSame([FooterKind::COUNT->value], $s->supportedAggregations());
        }
    }

    public function testConfigBounds(): void
    {
        $text = new TextStrategy();

        $this->assertEmpty($text->validateConfig([]));
        $this->assertEmpty($text->validateConfig(['minLength' => 1, 'maxLength' => 5, 'pattern' => '^[a-z]+$']));

        // minLength must be a non-negative integer.
        $this->assertNotEmpty($text->validateConfig(['minLength' => -1]));
        $this->assertNotEmpty($text->validateConfig(['minLength' => 'x']));
        // maxLength must be a positive integer.
        $this->assertNotEmpty($text->validateConfig(['maxLength' => 0]));
        $this->assertNotEmpty($text->validateConfig(['maxLength' => 'x']));
        // min > max.
        $this->assertNotEmpty($text->validateConfig(['minLength' => 5, 'maxLength' => 2]));
        // pattern must be a string and a valid regex.
        $this->assertNotEmpty($text->validateConfig(['pattern' => 123]));
        $this->assertNotEmpty($text->validateConfig(['pattern' => '[']));
    }

    public function testValueLengthAndPattern(): void
    {
        $text = new TextStrategy();
        $def = new CustomFieldDefinition();

        $config = ['minLength' => 3, 'maxLength' => 5, 'pattern' => '^[a-z]+$'];
        $this->assertCount(0, $text->validateValue('abc', $config, $def));
        $this->assertCount(1, $text->validateValue('ab', $config, $def));      // too short
        $this->assertCount(1, $text->validateValue('abcdef', $config, $def));  // too long
        $this->assertCount(1, $text->validateValue('ABC', $config, $def));     // pattern miss
        // Non-string scalar.
        $this->assertCount(1, $text->validateValue(42, [], $def));
    }

    public function testNormalizeTrims(): void
    {
        $text = new TextStrategy();

        $this->assertNull($text->normalize(null, []));
        $this->assertSame('hello', $text->normalize('  hello  ', []));
        // Non-string single passes through.
        $this->assertSame(7, $text->normalize(7, []));
        // Multi trims each element; non-array passes through.
        $this->assertSame(['a', 'b'], $text->normalize([' a ', ' b '], ['multi' => true]));
        $this->assertSame('x', $text->normalize('x', ['multi' => true]));
    }

    public function testSearchText(): void
    {
        $text = new TextStrategy();

        $this->assertNull($text->searchText(null, []));
        $this->assertSame('hi', $text->searchText('  hi  ', []));
        // Whitespace-only -> nothing searchable.
        $this->assertNull($text->searchText('   ', []));
        // Non-string -> null.
        $this->assertNull($text->searchText(5, []));
        // Multi: empties dropped, rest joined.
        $this->assertSame('a b', $text->searchText([' a ', '', ' b '], ['multi' => true]));
        $this->assertNull($text->searchText(['', '  '], ['multi' => true]));
    }

    public function testUrlSchemeValidation(): void
    {
        $url = new UrlStrategy();
        $def = new CustomFieldDefinition();

        $this->assertCount(0, $url->validateValue('https://example.com', [], $def));
        $this->assertCount(0, $url->validateValue('http://example.com', [], $def));
        $this->assertCount(0, $url->validateValue('mailto:alice@example.com', [], $def));
        // Disallowed scheme.
        $this->assertCount(1, $url->validateValue('ftp://example.com', [], $def));
        // Not a URL at all.
        $this->assertCount(1, $url->validateValue('not a url', [], $def));
        // Empty/whitespace short-circuits the URL check (only base rules apply,
        // which are satisfied here -> no violation).
        $this->assertCount(0, $url->validateValue('   ', [], $def));
    }

    public function testRichTextBehavesLikeText(): void
    {
        $rich = new RichTextStrategy();
        $def = new CustomFieldDefinition();

        $this->assertCount(0, $rich->validateValue('**bold**', [], $def));
        $this->assertSame('hello', $rich->searchText(' hello ', ['multi' => false]));
    }
}
