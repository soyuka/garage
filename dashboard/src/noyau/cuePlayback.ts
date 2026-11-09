import { CUES } from '../tokens/audition';
import type { Cue } from './cues';

export type CueMode = 'clear' | 'speech' | 'mic' | 'off';

export interface PlayingCue {
  ended: Promise<void>;
  setGain(gain: number): void;
  stop(): void;
}

export interface CueBackend {
  load(cue: Cue): Promise<boolean>;
  start(cue: Cue, gain: number, loop: boolean): PlayingCue | null;
}

interface ActiveCue {
  cue: Cue;
  playing: PlayingCue;
}

export class CuePlayback {
  readonly #backend: CueBackend;
  readonly #ready = new Set<Cue>();
  readonly #active = new Set<ActiveCue>();
  #loading: Promise<void> | null = null;
  #mode: CueMode = 'clear';
  #generation = 0;
  #loopScale = 1;
  #destroyed = false;

  constructor(backend: CueBackend) {
    this.#backend = backend;
  }

  preload(): Promise<void> {
    if (this.#loading) return this.#loading;
    const generation = this.#generation;
    this.#loading = Promise.all(
      CUES.names.map(async (cue) => {
        if ((await this.#backend.load(cue)) && generation === this.#generation) this.#ready.add(cue);
      }),
    ).then(() => undefined);

    return this.#loading;
  }

  setMode(mode: CueMode): void {
    this.#mode = mode;
    if (mode === 'mic' || mode === 'off') {
      for (const active of this.#active) active.playing.stop();
      this.#active.clear();
      return;
    }
    for (const active of this.#active) active.playing.setGain(this.#gain(active.cue));
  }

  play(cue: Cue): boolean {
    if (this.#destroyed || (this.#mode !== 'clear' && this.#mode !== 'speech') || !this.#ready.has(cue)) return false;
    const playing = this.#backend.start(cue, this.#gain(cue), cue === 'working');
    if (!playing) return false;
    const active = { cue, playing };
    this.#active.add(active);
    void playing.ended.finally(() => this.#active.delete(active));

    return true;
  }

  async playWhenReady(cue: Cue, timeoutMs: number, signal?: AbortSignal): Promise<boolean> {
    await Promise.race([this.preload(), new Promise<void>((resolve) => setTimeout(resolve, timeoutMs))]);
    if (signal?.aborted) return false;

    return this.play(cue);
  }

  async playMic(timeoutMs: number, signal?: AbortSignal): Promise<void> {
    const generation = this.#generation;
    await Promise.race([this.preload(), new Promise<void>((resolve) => setTimeout(resolve, timeoutMs))]);
    if (signal?.aborted || this.#destroyed || this.#mode !== 'mic' || generation !== this.#generation || !this.#ready.has('mic')) return;
    const playing = this.#backend.start('mic', CUES.gain.mic, false);
    if (!playing) return;
    const active = { cue: 'mic' as const, playing };
    this.#active.add(active);
    await Promise.race([playing.ended, new Promise<void>((resolve) => setTimeout(resolve, timeoutMs))]);
    playing.stop();
    await playing.ended;
    this.#active.delete(active);
  }

  setLoopScale(scale: number): void {
    this.#loopScale = scale;
    for (const active of this.#active) if (active.cue === 'working') active.playing.setGain(this.#gain(active.cue));
  }

  stop(cue: Cue): void {
    for (const active of this.#active) {
      if (active.cue !== cue) continue;
      active.playing.stop();
      this.#active.delete(active);
    }
  }

  destroy(): void {
    this.#destroyed = true;
    this.#generation += 1;
    this.setMode('off');
    this.#ready.clear();
  }

  activate(): void {
    if (!this.#destroyed) return;
    this.#destroyed = false;
    this.#loading = null;
    this.#mode = 'clear';
  }

  #gain(cue: Cue): number {
    const gain = cue === 'working' ? CUES.loopGain * this.#loopScale : CUES.gain[cue];

    return this.#mode === 'speech' ? gain * CUES.speechDuck : gain;
  }
}
