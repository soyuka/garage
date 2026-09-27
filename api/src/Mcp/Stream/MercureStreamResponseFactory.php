<?php

declare(strict_types=1);

namespace App\Mcp\Stream;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Turns an MCP response into a stream Caddy serves from the Mercure hub.
 *
 * The app answers with an empty 200 carrying the topic and a subscriber token; the `mercure_stream`
 * handler intercepts it, subscribes on the app's behalf and streams the hub's events to the client.
 * No PHP thread stays parked on the connection, so the publisher must be somewhere else entirely —
 * see docs/mcp-mercure-stream.md.
 */
final readonly class MercureStreamResponseFactory
{
    private const int MAX_TOPIC_LENGTH = 4096;

    /** RFC 6750 bearer token: excludes whitespace and CR/LF, so it cannot forge a header. */
    private const string TOKEN_PATTERN = '/\A[A-Za-z0-9._~+\/-]+=*\z/D';

    private const array ENTITY_HEADERS = [
        'Content-Length',
        'Content-Encoding',
        'Content-Range',
        'Content-Disposition',
        'Content-Digest',
        'Repr-Digest',
        'Digest',
        'ETag',
        'Last-Modified',
        'Transfer-Encoding',
        'Trailer',
    ];

    public function __construct(private StreamFactoryInterface $streams)
    {
    }

    public function delegate(ResponseInterface $response, string $topic, string $subscriberToken): ResponseInterface
    {
        if (200 !== $response->getStatusCode()) {
            throw new \InvalidArgumentException(\sprintf('Only a 200 can be delegated, got %d: delegating an error would leave the client waiting on a stream that never opens.', $response->getStatusCode()));
        }

        $this->assertTopic($topic);

        if ('' === $subscriberToken || 1 !== preg_match(self::TOKEN_PATTERN, $subscriberToken)) {
            throw new \InvalidArgumentException('The subscriber token is not a valid bearer token.');
        }

        foreach (self::ENTITY_HEADERS as $header) {
            $response = $response->withoutHeader($header);
        }

        return $response
            ->withHeader('Mercure-Stream', $topic)
            ->withHeader('Mercure-Stream-Token', $subscriberToken)
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream(''));
    }

    private function assertTopic(string $topic): void
    {
        if ('' === $topic) {
            throw new \InvalidArgumentException('The stream topic cannot be empty.');
        }

        if (\strlen($topic) > self::MAX_TOPIC_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('The stream topic exceeds %d bytes.', self::MAX_TOPIC_LENGTH));
        }

        if (1 === preg_match('/[\x00-\x1F\x7F]/', $topic)) {
            throw new \InvalidArgumentException('The stream topic contains control characters.');
        }

        // A subscription to "*" would hand the client every update the hub carries, for every owner.
        if ('*' === $topic) {
            throw new \InvalidArgumentException('The stream topic cannot be the wildcard.');
        }
    }
}
