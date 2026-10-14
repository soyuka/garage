<?php

declare(strict_types=1);

namespace App\Tests\Unit\Maintenance;

use App\Maintenance\PartRefRewriter;
use PHPUnit\Framework\TestCase;

final class PartRefRewriterTest extends TestCase
{
    public function testRewritesRefsAndKeepsProductNamesOnTheLabel(): void
    {
        $result = PartRefRewriter::rewrite([
            ['ref' => 'DOT4'],
            ['ref' => 'LOCTITE-243', 'qty' => 1],
            ['ref' => 'W 712/75'],
        ]);

        $this->assertSame([
            ['ref' => 'DOT 4'],
            ['ref' => 'frein-filet moyen', 'qty' => 1, 'label' => 'LOCTITE-243'],
            ['ref' => 'W 712/75'],
        ], $result->parts);
        $this->assertSame([['DOT4', 'DOT 4'], ['LOCTITE-243', 'frein-filet moyen']], $result->changes);
        $this->assertSame([], $result->collisions);
    }

    public function testIsIdempotent(): void
    {
        $once = PartRefRewriter::rewrite([['ref' => 'LOCTITE-243']]);
        $twice = PartRefRewriter::rewrite($once->parts);

        $this->assertSame([], $twice->changes);
        $this->assertSame($once->parts, $twice->parts);
    }

    public function testReportsACollisionWhenTwoLinesWouldShareARef(): void
    {
        $result = PartRefRewriter::rewrite([['ref' => 'DOT4'], ['ref' => 'DOT 4']]);

        $this->assertSame(['DOT 4'], $result->collisions);
    }

    public function testLeavesLinesWithoutAStringRefAlone(): void
    {
        $result = PartRefRewriter::rewrite([['label' => 'Huile moteur'], 'junk']);

        $this->assertSame([], $result->changes);
        $this->assertSame([['label' => 'Huile moteur'], 'junk'], $result->parts);
    }
}
