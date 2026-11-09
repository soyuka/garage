<?php

declare(strict_types=1);

namespace App\Motul;

use App\Exception\UpstreamException;

/**
 * Pulls the Next.js `__NEXT_DATA__` payload out of a server-rendered Motul page. The Oil Advisor
 * exposes no JSON recommendations endpoint (every /oil-advisor/* recs path 404s; the /_next/data
 * route is keyed by a buildId that rotates on each deploy), so the SSR HTML is the stable channel.
 */
final class NextData
{
    /**
     * @return array<array-key, mixed>
     */
    public static function extract(string $html): array
    {
        if (!preg_match('#<script id="__NEXT_DATA__"[^>]*>(.*?)</script>#s', $html, $m)) {
            throw UpstreamException::definitive('the Motul oil advisor', 'Motul page carried no __NEXT_DATA__ payload.');
        }

        $data = json_decode($m[1], true);
        if (!\is_array($data)) {
            throw UpstreamException::definitive('the Motul oil advisor', 'Motul __NEXT_DATA__ was not valid JSON.');
        }

        return $data;
    }
}
