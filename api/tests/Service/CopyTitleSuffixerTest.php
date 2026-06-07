<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CopyTitleSuffixer;
use PHPUnit\Framework\TestCase;

class CopyTitleSuffixerTest extends TestCase
{
    public function testAppendsSuffix(): void
    {
        $this->assertSame('My project (copy)', CopyTitleSuffixer::apply('My project', 255));
    }

    public function testIsIdempotent(): void
    {
        $once = CopyTitleSuffixer::apply('My project', 255);
        $this->assertSame($once, CopyTitleSuffixer::apply($once, 255));
    }

    public function testTruncatesToFitColumnWidth(): void
    {
        $title = str_repeat('a', 255);
        $result = CopyTitleSuffixer::apply($title, 255);

        $this->assertSame(255, mb_strlen($result));
        $this->assertStringEndsWith(' (copy)', $result);
    }
}
