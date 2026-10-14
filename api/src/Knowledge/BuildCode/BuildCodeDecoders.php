<?php

declare(strict_types=1);

namespace App\Knowledge\BuildCode;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class BuildCodeDecoders
{
    /**
     * @param iterable<BuildCodeDecoder> $decoders
     */
    public function __construct(
        #[AutowireIterator('app.build_code_decoder')]
        private iterable $decoders,
    ) {
    }

    public function forMake(string $make): ?BuildCodeDecoder
    {
        foreach ($this->decoders as $decoder) {
            if ($decoder->supports($make)) {
                return $decoder;
            }
        }

        return null;
    }
}
