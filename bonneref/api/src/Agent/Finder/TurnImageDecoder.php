<?php

declare(strict_types=1);

namespace App\Agent\Finder;

use Symfony\Component\HttpFoundation\Response;

/**
 * Decodes the photos a finder turn carries as base64 data URLs. The declared type is ignored: the
 * bytes are sniffed, so a renamed GIF or a script never reaches the model as an "image/png".
 */
final class TurnImageDecoder
{
    /**
     * @return list<TurnImage>
     *
     * @throws InvalidTurnImage
     */
    public function decode(mixed $raw): array
    {
        if (null === $raw) {
            return [];
        }
        if (!\is_array($raw) || !array_is_list($raw)) {
            throw new InvalidTurnImage('images must be a list of data URLs.', Response::HTTP_BAD_REQUEST);
        }
        if (\count($raw) > TurnInputLimits::MAX_IMAGES) {
            throw new InvalidTurnImage(\sprintf('At most %d images per turn.', TurnInputLimits::MAX_IMAGES), Response::HTTP_BAD_REQUEST);
        }

        $images = [];
        foreach ($raw as $dataUrl) {
            // @mago-expect analysis:possibly-undefined-variable the throw leaves only paths where preg_match matched and wrote $m
            if (!\is_string($dataUrl) || 1 !== preg_match('#^data:[\w/+.-]+;base64,#', $dataUrl, $m)) {
                throw new InvalidTurnImage('Each image must be a base64 data URL.', Response::HTTP_BAD_REQUEST);
            }
            $bytes = base64_decode(substr($dataUrl, \strlen($m[0])), true);
            if (false === $bytes || '' === $bytes) {
                throw new InvalidTurnImage('An image is not valid base64.', Response::HTTP_BAD_REQUEST);
            }
            if (\strlen($bytes) > TurnInputLimits::MAX_IMAGE_BYTES) {
                throw new InvalidTurnImage(\sprintf('Each image is capped at %d bytes.', TurnInputLimits::MAX_IMAGE_BYTES), Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
            }
            $mime = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($bytes);
            if (!\in_array($mime, TurnInputLimits::IMAGE_TYPES, true)) {
                throw new InvalidTurnImage('Accepted image types: image/jpeg, image/png, image/webp.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
            }
            $images[] = new TurnImage($mime, $bytes);
        }

        return $images;
    }
}
