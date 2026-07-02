<?php

namespace App\Tests\Ical;

use App\Ical\IcalWriter;
use PHPUnit\Framework\TestCase;

class IcalWriterTest extends TestCase
{
    private IcalWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new IcalWriter();
    }

    public function testEscapeText(): void
    {
        $this->assertSame(
            'a\\,b\\; c\\nd \\\\e',
            $this->writer->escapeText("a,b; c\nd \\e"),
        );
    }

    public function testFoldBreaksLongLinesWithLeadingSpace(): void
    {
        $line = 'DESCRIPTION:' . str_repeat('x', 200);
        $folded = $this->writer->fold($line);

        foreach (explode(IcalWriter::CRLF, $folded) as $i => $physical) {
            // First physical line <=75; continuations start with a space.
            $this->assertLessThanOrEqual(75, \strlen($physical));
            if ($i > 0) {
                $this->assertStringStartsWith(' ', $physical);
            }
        }
        // Unfolding restores the original.
        $this->assertSame($line, str_replace(IcalWriter::CRLF . ' ', '', $folded));
    }

    public function testFormatUtc(): void
    {
        $this->assertSame(
            '20260706T130000Z',
            $this->writer->formatUtc(new \DateTimeImmutable('2026-07-06T09:00:00-04:00')),
        );
    }

    public function testFormatLocal(): void
    {
        $this->assertSame(
            '20260706T090000',
            $this->writer->formatLocal(
                new \DateTimeImmutable('2026-07-06T13:00:00+00:00'),
                new \DateTimeZone('America/New_York'),
            ),
        );
    }
}
