<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SpaceIriResolver;
use PHPUnit\Framework\TestCase;

class SpaceIriResolverTest extends TestCase
{
    public function testExtractsIdFromIri(): void
    {
        $uuid = '0190a0b1-c2d3-7e4f-8a9b-0c1d2e3f4a5b';
        $this->assertSame($uuid, SpaceIriResolver::extractId('/spaces/' . $uuid));
    }

    public function testAcceptsBareUuid(): void
    {
        $uuid = '0190a0b1-c2d3-7e4f-8a9b-0c1d2e3f4a5b';
        $this->assertSame($uuid, SpaceIriResolver::extractId($uuid));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $uuid = '0190a0b1-c2d3-7e4f-8a9b-0c1d2e3f4a5b';
        $this->assertSame($uuid, SpaceIriResolver::extractId('  /spaces/' . $uuid . '  '));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'not an iri' => ['hello'];
        yield 'wrong prefix' => ['/projects/0190a0b1-c2d3-7e4f-8a9b-0c1d2e3f4a5b'];
        yield 'iri with non-uuid id' => ['/spaces/not-a-uuid'];
    }

    /**
     * @dataProvider invalidProvider
     */
    public function testRejectsInvalidInput(string $input): void
    {
        $this->assertNull(SpaceIriResolver::extractId($input));
    }
}
