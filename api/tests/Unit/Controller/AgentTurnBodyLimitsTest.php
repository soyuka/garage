<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\AgentTurnController;
use App\Storage\AcceptedUpload;
use PHPUnit\Framework\TestCase;

final class AgentTurnBodyLimitsTest extends TestCase
{
    private const int TURN_FILES_BYTES = AgentTurnController::MAX_FILES * AcceptedUpload::MAX_BYTES;

    public function testTheEdgeLetsATurnCarryItsFiles(): void
    {
        $caddyfile = (string) file_get_contents(\dirname(__DIR__, 3).'/frankenphp/Caddyfile');

        $this->assertSame(1, preg_match('~^\s*@(\w+) path /agent/turn\s*$~m', $caddyfile, $matcher), 'a matcher of its own for /agent/turn');
        $this->assertSame(1, preg_match('~request_body @'.$matcher[1].' \{\s*max_size (\d+)MiB\s*\}~', $caddyfile, $size));
        $this->assertGreaterThan(self::TURN_FILES_BYTES, (int) $size[1] * 1024 * 1024);

        $this->assertSame(1, preg_match('~^\s*@ordinaryBody not path (.+)$~m', $caddyfile, $ordinary));
        $this->assertContains('/agent/turn', preg_split('~\s+~', trim($ordinary[1])), 'the 1 MiB default must not apply to turns');
    }

    public function testPhpLetsATurnCarryItsFiles(): void
    {
        $this->assertGreaterThanOrEqual(AcceptedUpload::MAX_BYTES, ini_parse_quantity((string) \ini_get('upload_max_filesize')));
        $this->assertGreaterThan(self::TURN_FILES_BYTES, ini_parse_quantity((string) \ini_get('post_max_size')));
    }
}
