// Pure PCM plumbing for the streamed voice (CHOREOGRAPHY § "The voice starts before the browser asks").
// `/tts` relays pocket-tts as it is produced: a 44-byte WAV header whose sizes are a 2 GB placeholder,
// then 24 kHz mono 16-bit pieces of 80 ms. Nothing here touches an AudioContext — the impure edge is
// `speaker.ts` — so every rule (header walk, half-frame carry, clock placement) is a unit test.

export interface WavFormat {
  sampleRate: number;
  channels: number;
  bitsPerSample: number;
  /** where the samples start — the byte after the `data` chunk header */
  dataOffset: number;
}

const ascii = (bytes: Uint8Array, at: number, len: number): string => String.fromCharCode(...bytes.subarray(at, at + len));

/** RIFF/WAVE or not. Null while fewer than 12 bytes have arrived — the question cannot be answered yet. */
export function sniffWav(bytes: Uint8Array): boolean | null {
  if (bytes.length < 12) return null;
  return ascii(bytes, 0, 4) === 'RIFF' && ascii(bytes, 8, 4) === 'WAVE';
}

/**
 * Walk the chunk list to `fmt ` and `data`. Null means the header is not all here yet; a format this
 * player cannot schedule (anything but 16-bit integer PCM) throws, so the line is spent rather than
 * played as noise. The RIFF and `data` SIZES are deliberately never read: on a stream they are lies.
 */
export function parseWavHeader(bytes: Uint8Array): WavFormat | null {
  if (sniffWav(bytes) !== true) return null;

  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  let format: Omit<WavFormat, 'dataOffset'> | null = null;
  let at = 12;

  while (at + 8 <= bytes.length) {
    const id = ascii(bytes, at, 4);
    const size = view.getUint32(at + 4, true);
    const body = at + 8;

    if (id === 'fmt ') {
      if (body + 16 > bytes.length) return null;
      const tag = view.getUint16(body, true);
      const bitsPerSample = view.getUint16(body + 14, true);
      // 1 = integer PCM; 0xfffe = WAVE_FORMAT_EXTENSIBLE, whose sub-format pocket-tts never uses
      if (tag !== 1 || bitsPerSample !== 16) throw new Error(`unplayable WAV format (tag ${tag}, ${bitsPerSample} bits)`);
      format = { sampleRate: view.getUint32(body + 4, true), channels: view.getUint16(body + 2, true), bitsPerSample };
    } else if (id === 'data') {
      if (!format) throw new Error('WAV data before fmt');
      return { ...format, dataOffset: body };
    }

    at = body + size + (size & 1);
  }

  return null;
}

/**
 * Feeds arbitrary byte pieces, returns decoded frames as one Float32 plane per channel. The header
 * may straddle pieces and a piece may end mid-frame; both are carried, never guessed — a half-frame
 * decoded as a sample is a click at every chunk boundary.
 */
export class PcmDecoder {
  format: WavFormat | null = null;
  private pending: Uint8Array = new Uint8Array(0);

  push(chunk: Uint8Array): Float32Array<ArrayBuffer>[] | null {
    this.pending = this.pending.length === 0 ? chunk : join(this.pending, chunk);

    if (!this.format) {
      const format = parseWavHeader(this.pending);
      if (!format) return null;
      this.format = format;
      this.pending = this.pending.subarray(format.dataOffset);
    }

    const { channels } = this.format;
    const frameBytes = channels * 2;
    const frames = Math.floor(this.pending.length / frameBytes);
    if (frames === 0) return null;

    const view = new DataView(this.pending.buffer, this.pending.byteOffset, frames * frameBytes);
    const planes = Array.from({ length: channels }, () => new Float32Array(frames));
    for (let f = 0; f < frames; f++) {
      for (let c = 0; c < channels; c++) planes[c][f] = view.getInt16((f * channels + c) * 2, true) / 32768;
    }

    this.pending = this.pending.subarray(frames * frameBytes);
    return planes;
  }
}

function join(a: Uint8Array, b: Uint8Array): Uint8Array {
  const out = new Uint8Array(a.length + b.length);
  out.set(a, 0);
  out.set(b, a.length);
  return out;
}

/**
 * Where on the audio clock the next piece starts. Pieces are placed flush against one another so the
 * joins are sample-accurate and silent; the JS timer is never consulted (CHOREOGRAPHY § Binding
 * rules: "Schedule against the audio clock, never setTimeout"). If the clock has already passed the
 * planned start — synthesis fell behind playback — the piece plays now and `gap` says a hole was
 * heard, so the caller can reason about it instead of scheduling into the past.
 */
export class ChunkClock {
  private next: number | null = null;

  constructor(private readonly leadIn: number) {}

  /** the audio-clock time the last placed piece ends, or null before the first placement */
  get end(): number | null {
    return this.next;
  }

  place(now: number, duration: number): { at: number; gap: boolean } {
    const planned = this.next ?? now + this.leadIn;
    const gap = planned < now;
    const at = gap ? now : planned;
    this.next = at + duration;
    return { at, gap };
  }
}

/** Several pushes' planes as one set — what the primed head of a line is scheduled as. */
export function concatPlanes(parts: Float32Array<ArrayBuffer>[][]): Float32Array<ArrayBuffer>[] {
  if (parts.length === 0) return [];
  const channels = parts[0].length;
  const frames = parts.reduce((n, p) => n + p[0].length, 0);
  return Array.from({ length: channels }, (_, c) => {
    const out = new Float32Array(frames);
    let at = 0;
    for (const p of parts) {
      out.set(p[c], at);
      at += p[c].length;
    }
    return out;
  });
}
