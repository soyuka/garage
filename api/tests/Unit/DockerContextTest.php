<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DockerContextTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    public function testSecretsAndLocalStateStayOutOfTheBuildContext(): void
    {
        $this->assertTrue(self::ignored('.env.stage'), '.env.stage');
        $this->assertTrue(self::ignored('.env.dev'), '.env.dev');
        $this->assertTrue(self::ignored('.env.elevenlabs.local'), '.env.elevenlabs.local');
        $this->assertTrue(self::ignored('config/foo.local'), 'config/foo.local');
        $this->assertTrue(self::ignored('tondeuse/bill.pdf'), 'tondeuse/bill.pdf');
        $this->assertTrue(self::ignored('docker/cloudflared/config.yml'), 'docker/cloudflared/config.yml');
        $this->assertTrue(self::ignored('var/storage/bills/x.pdf'), 'var/storage/bills/x.pdf');
    }

    public function testMatcherFollowsDockerSemantics(): void
    {
        $this->assertTrue(self::ignored('README.md'));
        $this->assertTrue(self::ignored('src/Foo/notes.md'));
        $this->assertTrue(self::ignored('var/cache/x.php'));
        $this->assertFalse(self::ignored('src/Kernel.php'));
    }

    /**
     * Last matching line wins; `!` re-includes; a pattern matching a directory drops its contents.
     */
    private static function ignored(string $path): bool
    {
        $ignored = false;
        foreach (file(self::ROOT.'/.dockerignore', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            $negated = str_starts_with($line, '!');
            $pattern = trim(ltrim($negated ? substr($line, 1) : $line, '/'), '/');
            if (self::patternMatches($pattern, $path)) {
                $ignored = !$negated;
            }
        }

        return $ignored;
    }

    private static function patternMatches(string $pattern, string $path): bool
    {
        $regex = '#^'.strtr(preg_quote($pattern, '#'), ['\*\*/' => '(?:.*/)?', '\*\*' => '.*', '\*' => '[^/]*', '\?' => '[^/]']).'$#';
        $segments = explode('/', $path);
        for ($i = \count($segments); $i > 0; --$i) {
            if (preg_match($regex, implode('/', \array_slice($segments, 0, $i)))) {
                return true;
            }
        }

        return false;
    }
}
