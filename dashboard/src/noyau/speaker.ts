import { SPEECH } from '../tokens/audition';
import { ChunkClock, PcmDecoder, concatPlanes, sniffWav } from './pcm';

// The impure half of the streamed voice: a fetch body in, sound out of an AudioContext. Every rule it
// leans on — header walk, half-frame carry, where a piece goes on the clock — is pure and tested in
// pcm.ts; what is left here is the Web Audio wiring a repo with no browser cannot test.
//
// Why not <audio src=blob>: an element needs the whole body (or a MediaSource, which has no WAV
// codec), so it could not start before the line was fully synthesized — the 3–10 s gap between the
// card and the voice (docs/voice.md, 2026-09-05). Scheduling each 80 ms piece on the audio clock as it
// lands starts the sound ~250 ms after the request and keeps the joins sample-accurate.

export type Outcome = 'ended' | 'refused' | 'failed';

export interface Speaking {
  /** settles when the line has been heard to its end, was refused by the page, or could not be played */
  outcome: Promise<Outcome>;
  /**
   * The audio-clock time this line's LAST sample lands on — settled as soon as the whole body has been
   * placed, which is long before it has been heard (the body arrives ~12× faster than realtime, so a
   * 3.2 s line is fully scheduled ~0.3 s in). Feed it to the next line's `startAt` and the two are one
   * continuous signal; it is also the "this line is fully placed" signal that lets the queue promote
   * its successor early.
   *
   * Settles with the context's current time when nothing could be placed at all, so a line that failed
   * never makes the one behind it wait.
   */
  placed: Promise<number>;
  /** cut it now — a dismissal, a mute, a superseded line. The outcome is then meaningless. */
  stop(): void;
}

/**
 * A context created outside a gesture is suspended and stays so; Firefox with autoplay off for the origin additionally leaves `resume()` PENDING rather than
 * rejecting it, so the wait has to be bounded and the answer read back from `state`, never awaited.
 */
async function running(ctx: AudioContext): Promise<boolean> {
  // read through a call: the state changes across the await, which a narrowed property read would deny
  const state = (): AudioContextState => ctx.state;
  if (state() === 'running') return true;
  await Promise.race([
    ctx.resume().catch(() => undefined),
    new Promise<void>((resolve) => setTimeout(resolve, SPEECH.resumeGraceMs)),
  ]);
  return state() === 'running';
}

export function speak(ctx: AudioContext, body: ReadableStream<Uint8Array>, startAt: number | null = null): Speaking {
  let stopped = false;
  const sources = new Set<AudioBufferSourceNode>();
  const reader = body.getReader();

  // Settled once the body is fully placed — see {@see Speaking.placed}. Resolved exactly once, on
  // every exit path including the failures, so a caller chaining behind this line is never left
  // waiting on a promise that will not settle.
  let settlePlaced: (at: number) => void = () => undefined;
  const placed = new Promise<number>((resolve) => {
    settlePlaced = (at: number) => {
      resolve(at);
      settlePlaced = () => undefined;
    };
  });

  const stop = (): void => {
    stopped = true;
    settlePlaced(ctx.currentTime);
    void reader.cancel().catch(() => undefined);
    for (const source of sources) {
      try {
        source.stop();
      } catch {
        /* never started */
      }
      source.disconnect();
    }
    sources.clear();
  };

  const outcome = (async (): Promise<Outcome> => {
    if (!(await running(ctx))) {
      stop();
      return 'refused';
    }
    if (stopped) return 'ended';

    let settle: (o: Outcome) => void = () => undefined;
    const heard = new Promise<Outcome>((resolve) => (settle = resolve));
    let finished = false;

    // Each piece is its own source, placed flush against the previous one; the LAST one ending is the
    // line ending. Sources are removed as they end, so "finished and none left" is that moment.
    const place = (planes: Float32Array<ArrayBuffer>[], sampleRate: number, clock: ChunkClock): void => {
      const frames = planes[0].length;
      const buffer = ctx.createBuffer(planes.length, frames, sampleRate);
      planes.forEach((plane, channel) => buffer.copyToChannel(plane, channel));
      const source = ctx.createBufferSource();
      source.buffer = buffer;
      source.connect(ctx.destination);
      source.onended = () => {
        sources.delete(source);
        source.disconnect();
        if (finished && sources.size === 0) settle('ended');
      };
      sources.add(source);
      source.start(clock.place(ctx.currentTime, frames / sampleRate).at);
    };

    const head: Uint8Array[] = [];
    let isWav: boolean | null = null;
    const decoder = new PcmDecoder();
    const clock = new ChunkClock(SPEECH.leadInSeconds, startAt);
    let primed: Float32Array<ArrayBuffer>[][] = [];
    let primedSeconds = 0;
    let started = false;

    const onPcm = (chunk: Uint8Array): void => {
      const planes = decoder.push(chunk);
      if (!planes || !decoder.format) return;
      const { sampleRate } = decoder.format;
      if (started) return place(planes, sampleRate, clock);

      primed.push(planes);
      primedSeconds += planes[0].length / sampleRate;
      if (primedSeconds >= SPEECH.primeSeconds) {
        place(concatPlanes(primed), sampleRate, clock);
        primed = [];
        started = true;
      }
    };

    try {
      for (;;) {
        const { done, value } = await reader.read();
        if (stopped) return 'ended';
        if (done) break;
        if (!value || value.length === 0) continue;

        if (isWav === null) {
          head.push(value);
          const sniffed = sniffWav(head.length === 1 ? head[0] : concatBytes(head));
          if (sniffed === null) continue;
          isWav = sniffed;
          if (isWav) {
            for (const piece of head) onPcm(piece);
            head.length = 0;
          }
          continue;
        }

        if (isWav) onPcm(value);
        else head.push(value);
      }
    } catch {
      // A dropped connection mid-line: play what arrived. A body that yielded nothing is a failure
      // below; anything else ends where it ended.
    }

    if (stopped) return 'ended';

    if (isWav === false) {
      // mp3 or anything else: no streaming decoder in the platform, so decode the complete body once.
      try {
        const buffer = await ctx.decodeAudioData(toArrayBuffer(concatBytes(head)));
        if (stopped) return 'ended';
        finished = true;
        place(
          Array.from({ length: buffer.numberOfChannels }, (_, c) => buffer.getChannelData(c)),
          buffer.sampleRate,
          clock,
        );
        settlePlaced(clock.end ?? ctx.currentTime);
        return heard;
      } catch {
        settlePlaced(ctx.currentTime);
        return 'failed';
      }
    }

    if (primed.length > 0 && decoder.format) place(concatPlanes(primed), decoder.format.sampleRate, clock);
    finished = true;
    // The whole body is on the clock now: whoever is queued behind this line can be scheduled flush.
    settlePlaced(clock.end ?? ctx.currentTime);
    if (sources.size === 0) return started || primed.length > 0 ? 'ended' : 'failed';
    return heard;
  })();

  // A refusal or an early return leaves nothing on the clock; settle so nothing chains onto a promise
  // that will not resolve.
  void outcome.then((o) => ('ended' === o ? undefined : settlePlaced(ctx.currentTime)));

  return { outcome, placed, stop };
}

function concatBytes(parts: Uint8Array[]): Uint8Array {
  const out = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
  let at = 0;
  for (const p of parts) {
    out.set(p, at);
    at += p.length;
  }
  return out;
}

function toArrayBuffer(bytes: Uint8Array): ArrayBuffer {
  return bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength) as ArrayBuffer;
}
