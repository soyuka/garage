import { describe, expect, it } from 'vitest';
import { ChunkClock, PcmDecoder, concatPlanes, parseWavHeader, sniffWav } from './pcm';

// pocket-tts streams 24 kHz mono 16-bit PCM WAV in 3840-byte pieces (80 ms) every ~60 ms, behind a
// 44-byte header whose sizes are a 2 GB placeholder (measured 2026-09-05, docs/voice.md). The ear
// plays each piece as it lands, so these rules decide whether the first word is heard at 250 ms or
// after the whole line — and whether a piece split at an odd byte becomes a click.

function header(sampleRate = 24000, channels = 1, bits = 16, formatTag = 1): Uint8Array {
  const b = new Uint8Array(44);
  const v = new DataView(b.buffer);
  const ascii = (at: number, s: string) => { for (let i = 0; i < s.length; i++) b[at + i] = s.charCodeAt(i); };
  ascii(0, 'RIFF'); v.setUint32(4, 2000000036, true); ascii(8, 'WAVE');
  ascii(12, 'fmt '); v.setUint32(16, 16, true);
  v.setUint16(20, formatTag, true); v.setUint16(22, channels, true); v.setUint32(24, sampleRate, true);
  v.setUint32(28, (sampleRate * channels * bits) / 8, true); v.setUint16(32, (channels * bits) / 8, true); v.setUint16(34, bits, true);
  ascii(36, 'data'); v.setUint32(40, 2000000000, true);
  return b;
}

function pcm16(...samples: number[]): Uint8Array {
  const b = new Uint8Array(samples.length * 2);
  const v = new DataView(b.buffer);
  samples.forEach((s, i) => v.setInt16(i * 2, s, true));
  return b;
}

function concat(...parts: Uint8Array[]): Uint8Array {
  const out = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
  let at = 0;
  for (const p of parts) { out.set(p, at); at += p.length; }
  return out;
}

describe('sniffWav', () => {
  it('cannot tell before 12 bytes have arrived', () => {
    expect(sniffWav(header().subarray(0, 11))).toBeNull();
  });

  it('recognises RIFF/WAVE and rejects anything else — an mp3 body is decoded whole, not streamed', () => {
    expect(sniffWav(header())).toBe(true);
    expect(sniffWav(new TextEncoder().encode('ID3\x04\x00fake-mp3-bytes'))).toBe(false);
  });
});

describe('parseWavHeader', () => {
  it('reads the format and where the samples start, ignoring the placeholder sizes', () => {
    expect(parseWavHeader(header())).toEqual({ sampleRate: 24000, channels: 1, bitsPerSample: 16, dataOffset: 44 });
  });

  it('asks for more while the header is still arriving', () => {
    expect(parseWavHeader(header().subarray(0, 40))).toBeNull();
  });

  it('skips a chunk it does not know to reach the data', () => {
    const h = header();
    const list = new Uint8Array(8 + 4);
    const v = new DataView(list.buffer);
    list.set([0x4c, 0x49, 0x53, 0x54], 0); // LIST
    v.setUint32(4, 4, true);
    expect(parseWavHeader(concat(h.subarray(0, 36), list, h.subarray(36)))?.dataOffset).toBe(56);
  });

  it('refuses a sample format it cannot schedule', () => {
    expect(() => parseWavHeader(header(24000, 1, 8))).toThrow();
    expect(() => parseWavHeader(header(24000, 1, 16, 3))).toThrow(); // IEEE float
  });
});

describe('PcmDecoder', () => {
  it('yields nothing until the header is complete, then the first samples in the same push', () => {
    const d = new PcmDecoder();
    const h = header();
    expect(d.push(h.subarray(0, 20))).toBeNull();
    const planes = d.push(concat(h.subarray(20), pcm16(0, 32767, -32768)));
    expect(d.format?.sampleRate).toBe(24000);
    expect(planes).toHaveLength(1);
    expect(Array.from(planes![0])).toEqual([0, 32767 / 32768, -1]);
  });

  it('carries a half frame across pushes instead of decoding it as a sample — the click', () => {
    const d = new PcmDecoder();
    d.push(header());
    const bytes = pcm16(1000, -1000, 500);
    const a = d.push(bytes.subarray(0, 3));
    const b = d.push(bytes.subarray(3));
    expect(Array.from(a![0])).toEqual([1000 / 32768]);
    expect(Array.from(b![0])).toEqual([-1000 / 32768, 500 / 32768]);
  });

  it('de-interleaves stereo into one plane per channel', () => {
    const d = new PcmDecoder();
    d.push(header(24000, 2));
    const planes = d.push(pcm16(1, 2, 3, 4));
    expect(planes).toHaveLength(2);
    expect(Array.from(planes![0])).toEqual([1 / 32768, 3 / 32768]);
    expect(Array.from(planes![1])).toEqual([2 / 32768, 4 / 32768]);
  });

  it('returns null for a push that completes no frame', () => {
    const d = new PcmDecoder();
    d.push(header());
    expect(d.push(pcm16(7).subarray(0, 1))).toBeNull();
  });
});

describe('ChunkClock', () => {
  // The audio clock is the only clock (CHOREOGRAPHY § Binding rules: never setTimeout). Each piece
  // is placed exactly where the previous one ends, so the joins are sample-accurate and silent.
  it('places the first piece a lead-in ahead of now and each next one flush against the last', () => {
    const c = new ChunkClock(0.03);
    expect(c.place(10, 0.08)).toEqual({ at: 10.03, gap: false });
    expect(c.place(10.05, 0.08)).toEqual({ at: 10.11, gap: false });
    expect(c.end).toBeCloseTo(10.19);
  });

  it('re-anchors on an underrun and says so — a late piece plays now, never in the past', () => {
    const c = new ChunkClock(0.03);
    c.place(10, 0.08);
    expect(c.place(10.5, 0.08)).toEqual({ at: 10.5, gap: true });
    expect(c.end).toBeCloseTo(10.58);
  });

  it('has no end before anything was placed', () => {
    expect(new ChunkClock(0.03).end).toBeNull();
  });
});

describe('concatPlanes', () => {
  it('joins pushes per channel, in order', () => {
    const joined = concatPlanes([[new Float32Array([1, 2]), new Float32Array([5, 6])], [new Float32Array([3]), new Float32Array([7])]]);
    expect(joined.map((p) => Array.from(p))).toEqual([[1, 2, 3], [5, 6, 7]]);
  });

  it('is empty for nothing', () => {
    expect(concatPlanes([])).toEqual([]);
  });
});
