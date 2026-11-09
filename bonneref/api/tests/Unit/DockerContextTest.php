<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

final class DockerContextTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /**
     * @return iterable<string, array{string}>
     */
    public static function configuredFiles(): iterable
    {
        foreach ((new Finder())->files()->in(self::ROOT.'/config/packages')->name('*.yaml') as $config) {
            foreach (self::fileValues(Yaml::parseFile($config->getPathname(), Yaml::PARSE_CUSTOM_TAGS)) as $path) {
                if (str_starts_with($path, '%kernel.project_dir%/')) {
                    $relative = substr($path, \strlen('%kernel.project_dir%/'));
                    yield $config->getFilename().': '.$relative => [$relative];
                }
            }
        }
    }

    #[DataProvider('configuredFiles')]
    public function testFileReadByConfigReachesTheImage(string $path): void
    {
        $this->assertFileExists(self::ROOT.'/'.$path);
        $this->assertFalse(self::ignored($path), \sprintf('api/.dockerignore drops "%s", which the config reads at runtime.', $path));
    }

    public function testSecretsAndLocalStateStayOutOfTheBuildContext(): void
    {
        $this->assertTrue(self::ignored('.env.stage'), '.env.stage');
        $this->assertTrue(self::ignored('.env.dev'), '.env.dev');
        $this->assertTrue(self::ignored('.env.local'), '.env.local');
        $this->assertTrue(self::ignored('config/foo.local'), 'config/foo.local');
        $this->assertTrue(self::ignored('var/storage/x.jpg'), 'var/storage/x.jpg');
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

    /**
     * @return iterable<string>
     */
    private static function fileValues(mixed $node): iterable
    {
        if (!\is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if ('file' === $key && \is_string($value)) {
                yield $value;
            }
            yield from self::fileValues($value);
        }
    }
}
