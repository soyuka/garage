<?php

declare(strict_types=1);

namespace App\Tests\Unit\Speech;

use App\Speech\Wav;
use PHPUnit\Framework\TestCase;

/**
 * `Wav::header()` is the shape both TTS backends now emit — pocket-tts on the wire since
 * 2026-09-04, ElevenLabs since 2026-09-05 (`ElevenLabsSpeechClient::stream()`) — so the dashboard's
 * streaming decoder (`noyau/pcm.ts`) sees one header regardless of which backend spoke.
 */
final class WavTest extends TestCase
{
    public function testHeaderIsFortyFourBytes(): void
    {
        $this->assertSame(44, \strlen(Wav::header(24000)));
    }

    public function testHeaderCarriesTheCanonicalChunkIdsAtTheRightOffsets(): void
    {
        $header = Wav::header(24000);

        $this->assertSame('RIFF', substr($header, 0, 4));
        $this->assertSame('WAVE', substr($header, 8, 4));
        $this->assertSame('fmt ', substr($header, 12, 4));
        $this->assertSame('data', substr($header, 36, 4));
    }

    public function testFmtFieldsAreLittleEndianAndReflectTheArguments(): void
    {
        $header = Wav::header(sampleRate: 24000, channels: 2, bitsPerSample: 16);

        $fmt = unpack('vformat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', substr($header, 20, 16));

        $this->assertSame(1, $fmt['format'], 'format tag must be 1 (PCM)');
        $this->assertSame(2, $fmt['channels']);
        $this->assertSame(24000, $fmt['sampleRate']);
        $this->assertSame(24000 * 2 * 2, $fmt['byteRate'], 'byte rate = sampleRate * blockAlign');
        $this->assertSame(4, $fmt['blockAlign'], 'block align = channels * bytesPerSample');
        $this->assertSame(16, $fmt['bitsPerSample']);
    }

    /**
     * pocket-tts streams these EXACT placeholder sizes (verified on the wire, docs/voice.md
     * 2026-09-05: RIFF 2 000 000 036, data 2 000 000 000). ElevenLabs' streamed PCM has to emit the
     * identical placeholder so the browser sees one header shape whichever backend spoke.
     */
    public function testPlaceholderSizesMatchTheOnesPocketTtsStreamsOnTheWire(): void
    {
        $header = Wav::header(24000);

        $this->assertSame(2000000036, unpack('V', substr($header, 4, 4))[1], 'RIFF size placeholder');
        $this->assertSame(2000000000, unpack('V', substr($header, 40, 4))[1], 'data size placeholder');
    }

    public function testWithRealLengthsStampsTheTrueRiffAndDataSizes(): void
    {
        $pcm = str_repeat("\x01\x02", 512);
        $wav = Wav::withRealLengths(Wav::header(24000).$pcm);

        $this->assertSame(\strlen($wav) - 8, unpack('V', substr($wav, 4, 4))[1], 'RIFF size = total - 8');
        $this->assertSame(\strlen($pcm), unpack('V', substr($wav, 40, 4))[1], 'data size = N bytes of payload');
        $this->assertSame($pcm, substr($wav, 44), 'the samples themselves must be untouched');
    }

    /** A body that is not RIFF/WAVE at all must pass through rather than be corrupted by a patch. */
    public function testANonWavBodyIsReturnedUntouched(): void
    {
        $this->assertSame('not audio at all', Wav::withRealLengths('not audio at all'));
    }
}
