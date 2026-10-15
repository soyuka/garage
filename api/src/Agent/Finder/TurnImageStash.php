<?php

declare(strict_types=1);

namespace App\Agent\Finder;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Ulid;

/**
 * Hands a turn's photos from the HTTP request to the worker without putting them in the queued
 * message: a message that fails lands in the `failed` transport for good, and the photos must not.
 * The stash is short-lived and the worker forgets it once the turn settles, retried or not.
 */
final readonly class TurnImageStash
{
    private const int TTL = 900;

    public function __construct(
        #[Autowire(service: 'cache.turn_images')]
        private CacheItemPoolInterface $pool,
    ) {
    }

    /**
     * @param list<TurnImage> $images
     */
    public function put(array $images): string
    {
        $key = 'turn_images_'.new Ulid();
        $item = $this->pool->getItem($key);
        $item->set(array_map(static fn (TurnImage $i): array => ['mimeType' => $i->mimeType, 'bytes' => base64_encode($i->bytes)], $images));
        $item->expiresAfter(self::TTL);
        $this->pool->save($item);

        return $key;
    }

    /**
     * @return list<TurnImage>
     */
    public function get(string $key): array
    {
        $item = $this->pool->getItem($key);
        if (!$item->isHit() || !\is_array($item->get())) {
            return [];
        }

        return array_values(array_map(
            static fn (array $i): TurnImage => new TurnImage((string) $i['mimeType'], (string) base64_decode((string) $i['bytes'], true)),
            $item->get(),
        ));
    }

    public function forget(string $key): void
    {
        $this->pool->deleteItem($key);
    }
}
