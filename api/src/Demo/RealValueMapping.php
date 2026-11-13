<?php

declare(strict_types=1);

namespace App\Demo;

/**
 * The real → fake rewrite list kept outside the repository (the 2026-11-02 history rewrite format):
 * `plate:REAL==>FAKE` lines match every spelling of a plate (dash, space, underscore, none, %20, any
 * case) and render the fake in the matched separator and case; `regex:PATTERN==>REPLACEMENT` lines are
 * applied after the plates, in file order.
 */
final readonly class RealValueMapping
{
    /**
     * @param list<array{line: int, pattern: non-empty-string, replacement: string|\Closure(array<array-key, string>): string}> $rules
     */
    private function __construct(private array $rules)
    {
    }

    public static function fromFile(string $path): self
    {
        // Not is_readable(): it rejects php://stdin, which keeps the real values off the container filesystem.
        set_error_handler(static fn (): bool => true);
        try {
            $lines = file($path, \FILE_IGNORE_NEW_LINES);
        } finally {
            restore_error_handler();
        }
        if (false === $lines) {
            throw new DemoFixtureException(\sprintf('Cannot read the mapping file "%s".', $path));
        }

        $plates = [];
        $regexes = [];
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }

            $number = $index + 1;
            $kind = strstr($line, ':', true);
            $sides = explode('==>', substr($line, \strlen((string) $kind) + 1), 2);
            if (false === $kind || 2 !== \count($sides) || '' === $sides[0]) {
                throw new DemoFixtureException(\sprintf('Mapping line %d is not "plate:REAL==>FAKE" nor "regex:PATTERN==>REPLACEMENT".', $number));
            }

            match ($kind) {
                'plate' => $plates[] = self::plateRule($number, $sides[0], $sides[1]),
                'regex' => $regexes[] = self::regexRule($number, $sides[0], $sides[1]),
                default => throw new DemoFixtureException(\sprintf('Mapping line %d: unknown kind "%s".', $number, $kind)),
            };
        }

        return new self([...$plates, ...$regexes]);
    }

    /**
     * Every spelling of the plate: the three groups joined by one separator used twice.
     *
     * @return non-empty-string
     */
    public static function platePattern(string $plate): string
    {
        [$first, $second, $third] = array_map(static fn (string $group): string => preg_quote($group, '/'), self::plateGroups($plate));

        return \sprintf('/(?<![A-Za-z0-9])%s(-| |_|%%20|)%s\1%s(?![A-Za-z0-9])/i', $first, $second, $third);
    }

    public function apply(string $text): string
    {
        foreach ($this->rules as $rule) {
            $text = self::applyRule($rule, $text);
        }

        return $text;
    }

    /**
     * A rule leaks when applying it again would still change the text: a fake that its own pattern
     * matches (a lookbehind on a label followed by digits) is not a leak.
     *
     * @return list<int> the mapping lines whose real side is still in the text
     */
    public function leaks(string $text): array
    {
        $lines = [];
        foreach ($this->rules as $rule) {
            if (self::applyRule($rule, $text) !== $text) {
                $lines[] = $rule['line'];
            }
        }

        return $lines;
    }

    /**
     * @param array{line: int, pattern: non-empty-string, replacement: string|\Closure(array<array-key, string>): string} $rule
     */
    private static function applyRule(array $rule, string $text): string
    {
        $replacement = $rule['replacement'];

        return $replacement instanceof \Closure
            ? (string) preg_replace_callback($rule['pattern'], $replacement, $text)
            : (string) preg_replace($rule['pattern'], $replacement, $text);
    }

    /**
     * @return array{line: int, pattern: non-empty-string, replacement: \Closure(array<array-key, string>): string}
     */
    private static function plateRule(int $line, string $real, string $fake): array
    {
        $fakeGroups = self::plateGroups($fake);

        return [
            'line' => $line,
            'pattern' => self::platePattern($real),
            'replacement' => static function (array $match) use ($fakeGroups): string {
                $whole = $match[0] ?? '';
                $separator = $match[1] ?? '';
                $rendered = implode(\is_string($separator) ? $separator : '', $fakeGroups);

                return \is_string($whole) && $whole === strtolower($whole) ? strtolower($rendered) : $rendered;
            },
        ];
    }

    /**
     * @return array{line: int, pattern: non-empty-string, replacement: string}
     */
    private static function regexRule(int $line, string $pattern, string $replacement): array
    {
        $compiled = "\x01".$pattern."\x01u";
        set_error_handler(static fn (): bool => true);
        try {
            $valid = false !== preg_match($compiled, '');
        } finally {
            restore_error_handler();
        }
        if (!$valid) {
            throw new DemoFixtureException(\sprintf('Mapping line %d: invalid regex.', $line));
        }

        return ['line' => $line, 'pattern' => $compiled, 'replacement' => $replacement];
    }

    /**
     * @return array{string, string, string}
     */
    private static function plateGroups(string $plate): array
    {
        $groups = preg_split('/[^A-Za-z0-9]+/', trim($plate), flags: \PREG_SPLIT_NO_EMPTY);
        if (false === $groups || 3 !== \count($groups)) {
            throw new DemoFixtureException('A plate must be three groups, e.g. AB-123-CD.');
        }

        return [$groups[0], $groups[1], $groups[2]];
    }
}
