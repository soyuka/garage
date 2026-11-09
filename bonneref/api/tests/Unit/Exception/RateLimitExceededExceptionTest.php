<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exception;

use App\Exception\RateLimitExceededException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class RateLimitExceededExceptionTest extends TestCase
{
    protected function tearDown(): void
    {
        // A bare `new Clock()` as the global clock reads Clock::get(), i.e. itself, and recurses forever.
        Clock::set(new NativeClock());
    }

    public function testRetryAfterIsCountedFromTheClock(): void
    {
        Clock::set(new MockClock('2030-01-01 00:00:00'));

        $this->assertSame(90, (new RateLimitExceededException(new \DateTimeImmutable('2030-01-01 00:01:30')))->retryAfterSeconds());
    }
}
