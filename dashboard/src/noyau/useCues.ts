import { useCallback, useEffect, useMemo, useRef } from 'react';
import { audioContext, holdAudio, releaseAudio } from './audioContext';
import { CUE_BURST_INIT, cueBurstReduce, cueRepeats, loopScale, loopSounds, type Cue, type CueBurst, type Work } from './cues';
import { SPATIAL_PALETTE, type CuePalette } from './palettes';
import { CuePlayback, type CueBackend, type PlayingCue } from './cuePlayback';

const buffers = new Map<string, Promise<AudioBuffer | null>>();

function browserBackend(palette: CuePalette): CueBackend {
  return {
    async load(cue) {
      const url = palette.url[cue];
      if (!url) return false;
      const ctx = audioContext();
      let pending = buffers.get(url);
      if (!pending) {
        pending = fetch(url)
          .then((res) => (res.ok ? res.arrayBuffer() : null))
          .then((bytes) => (bytes ? ctx.decodeAudioData(bytes) : null))
          .catch(() => null);
        buffers.set(url, pending);
      }

      return null !== (await pending);
    },
    start(cue, initialGain, loop): PlayingCue | null {
      const ctx = audioContext();
      const url = palette.url[cue];
      if (ctx.state !== 'running' || !url) return null;
      const pending = buffers.get(url);
      if (!pending) return null;
      let source: AudioBufferSourceNode | null = null;
      let gain: GainNode | null = null;
      let stopped = false;
      let targetGain = initialGain;
      let finish!: () => void;
      const ended = new Promise<void>((resolve) => (finish = resolve));
      void pending.then((buffer) => {
        if (!buffer || stopped || ctx.state !== 'running') {
          finish();
          return;
        }
        source = ctx.createBufferSource();
        gain = ctx.createGain();
        source.buffer = buffer;
        source.loop = loop;
        gain.gain.setValueAtTime(loop ? 0 : targetGain, ctx.currentTime);
        source.connect(gain).connect(ctx.destination);
        holdAudio();
        source.onended = () => {
          source?.disconnect();
          gain?.disconnect();
          releaseAudio();
          finish();
        };
        source.start();
        if (loop) gain.gain.setTargetAtTime(targetGain, ctx.currentTime, palette.loop.fadeInMs / 3000);
      });

      return {
        ended,
        setGain(value) {
          targetGain = value;
          gain?.gain.setTargetAtTime(value, ctx.currentTime, 0.025);
        },
        stop() {
          if (stopped) return;
          stopped = true;
          if (!source || !gain) {
            finish();
            return;
          }
          const fadeMs = loop ? palette.loop.fadeOutMs : 60;
          gain.gain.setTargetAtTime(0, ctx.currentTime, fadeMs / 4000);
          window.setTimeout(() => {
            try {
              source?.stop();
            } catch {
              finish();
            }
          }, fadeMs);
        },
      };
    },
  };
}

export interface ArmedMicCue {
  play(): Promise<void>;
  cancel(): void;
}

export interface CueControls {
  play(cue: Exclude<Cue, 'working' | 'mic' | 'opening'>): void;
  opening(): void;
  cancelOpening(): void;
  armMic(): ArmedMicCue;
}

export function useCues(enabled: boolean, work: Omit<Work, 'sinceMs'> & { listening: boolean }, palette: CuePalette = SPATIAL_PALETTE): CueControls {
  const player = useMemo(() => new CuePlayback(browserBackend(palette)), [palette]);
  const burst = useRef<CueBurst>(CUE_BURST_INIT);
  const lastPlayed = useRef(new Map<Cue, number>());
  const answered = work.answered ?? false;
  const openingArmed = useRef(false);
  const openingController = useRef<AbortController | null>(null);

  useEffect(() => {
    player.activate();
    if (enabled) void player.preload();

    return () => player.destroy();
  }, [enabled, player]);

  useEffect(() => {
    player.setMode(!enabled ? 'off' : work.listening ? 'mic' : work.speaking ? 'speech' : 'clear');
  }, [enabled, player, work.listening, work.speaking]);

  useEffect(() => {
    // Every gesture may be the first one the browser lets the context start in; a palette without an
    // opening cue still needs the context running for its first cue.
    const unlock = () => {
      const ctx = audioContext();
      if (!openingArmed.current) {
        if (ctx.state !== 'running') void ctx.resume().catch(() => undefined);
        return;
      }
      openingArmed.current = false;
      const controller = openingController.current;
      void ctx.resume().then(() => player.playWhenReady('opening', 650, controller?.signal)).catch(() => undefined);
    };
    window.addEventListener('pointerdown', unlock, { passive: true });
    window.addEventListener('keydown', unlock);

    return () => {
      window.removeEventListener('pointerdown', unlock);
      window.removeEventListener('keydown', unlock);
    };
  }, [player]);

  useEffect(() => {
    if (work.busy || work.listening) {
      openingArmed.current = false;
      openingController.current?.abort();
      openingController.current = null;
    }
  }, [work.busy, work.listening]);

  useEffect(() => {
    const { afterMs, thinAfterMs, thinGain } = palette.loop;
    const timers: number[] = [];
    const stop = () => {
      timers.forEach((id) => window.clearTimeout(id));
      player.stop('working');
      player.setLoopScale(1);
    };
    if (!enabled || !work.busy || work.listening || answered) {
      stop();
      return;
    }
    const startedAt = performance.now();
    timers.push(
      window.setTimeout(() => {
        if (loopSounds({ busy: work.busy, speaking: work.speaking, answered, sinceMs: performance.now() - startedAt }, afterMs)) player.play('working');
      }, afterMs),
    );
    if (Number.isFinite(thinAfterMs)) timers.push(window.setTimeout(() => player.setLoopScale(loopScale(performance.now() - startedAt, thinAfterMs, thinGain)), thinAfterMs));

    return () => timers.forEach((id) => window.clearTimeout(id));
  }, [enabled, palette, player, work.busy, work.listening, answered]);

  const play = useCallback(
    (cue: Exclude<Cue, 'working' | 'mic' | 'opening'>) => {
      if (!enabled || work.listening) return;
      const at = performance.now();
      if (!cueRepeats(lastPlayed.current.get(cue), at, palette.repeatMs?.[cue])) return;
      const out = cueBurstReduce(burst.current, { type: 'want', cue, at });
      burst.current = out.state;
      if (!out.fire) return;
      lastPlayed.current.set(out.fire, at);
      player.play(out.fire);
    },
    [enabled, palette, player, work.listening],
  );

  const opening = useCallback(() => {
    openingController.current?.abort();
    openingController.current = new AbortController();
    openingArmed.current = enabled;
  }, [enabled]);

  const cancelOpening = useCallback(() => {
    openingArmed.current = false;
    openingController.current?.abort();
    openingController.current = null;
  }, []);

  const armMic = useCallback((): ArmedMicCue => {
    openingArmed.current = false;
    openingController.current?.abort();
    openingController.current = null;
    const ctx = audioContext();
    let live = true;
    const controller = new AbortController();
    holdAudio();
    void ctx.resume().catch(() => undefined);
    const release = () => {
      if (!live) return;
      live = false;
      controller.abort();
      player.stop('mic');
      releaseAudio();
    };

    return {
      async play() {
        if (!live) return;
        await player.playMic(650, controller.signal);
        release();
      },
      cancel: release,
    };
  }, [player]);

  return { play, opening, cancelOpening, armMic };
}
