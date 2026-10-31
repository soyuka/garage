<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use Symfony\AI\Platform\Result\RawResultInterface;

/**
 * Counts reads of the raw HTTP body, so a test can prove a code path never forced the fetch.
 */
final class CountingRawResult implements RawResultInterface
{
    public int $reads = 0;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    public function getData(): array
    {
        ++$this->reads;

        return $this->data;
    }

    public function getDataStream(): iterable
    {
        yield $this->data;
    }

    public function getObject(): object
    {
        return (object) $this->data;
    }
}
