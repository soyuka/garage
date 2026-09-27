import { useCallback, useEffect, useMemo, useRef } from 'react';
import { CUES } from '../tokens/audition';
import { audioContext, holdAudio, releaseAudio } from './audioContext';
import { CUE_BURST_INIT, cueBurstReduce, loopSounds, type Cue, type CueBurst, type Work } from './cues';
import { CuePlayback, type CueBackend, type PlayingCue } from './cuePlayback';

const CUE_URL: Record<Cue, string> = {
  select: '/audio/spatial-2026-09-11/select.wav',
  panel: '/audio/spatial-2026-09-11/panel.wav',
  reveal: '/audio/spatial-2026-09-11/reveal.wav',
  card: '/audio/spatial-2026-09-11/card.wav',
  dismiss: '/audio/spatial-2026-09-11/dismiss.wav',
  complete: '/audio/spatial-2026-09-11/complete.wav',
  blocked: '/audio/spatial-2026-09-11/blocked.wav',
  mic: '/audio/spatial-2026-09-11/mic.wav',
  working: '/audio/spatial-2026-09-11/working.wav',
  opening: '/audio/spatial-2026-09-11/opening.wav',
};

const buffers = new Map<Cue, Promise<AudioBuffer | null>>();

function browserBackend(): CueBackend {
  return {
    async load(cue) {
      const ctx = audioContext();
      let pending = buffers.get(cue);
      if (!pending) {
        pending = fetch(CUE_URL[cue])
          .then((res) => (res.ok ? res.arrayBuffer() : null))
          .then((bytes) => (bytes ? ctx.decodeAudioData(bytes) : null))
          .catch(() => null);
        buffers.set(cue, pending);
      }

      return null !== (await pending);
    },
    start(cue, initialGain, loop): PlayingCue | null {
      const ctx = audioContext();
      if (ctx.state !== 'running') return null;
      const pending = buffers.get(cue);
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
        if (loop) gain.gain.setTargetAtTime(targetGain, ctx.currentTime, CUES.loopFadeSeconds / 3);
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
          gain.gain.setTargetAtTime(0, ctx.currentTime, 0.015);
          window.setTimeout(() => {
            try {
              source?.stop();
            } catch {
              finish();
            }
          }, 60);
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

export function useCues(enabled: boolean, work: Omit<Work, 'sinceMs'> & { listening: boolean }): CueControls {
  const player = useMemo(() => new CuePlayback(browserBackend()), []);
  const burst = useRef<CueBurst>(CUE_BURST_INIT);
  const loopTimer = useRef<number | null>(null);
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
    const unlock = () => {
      if (!openingArmed.current) return;
      openingArmed.current = false;
      const controller = openingController.current;
      const ctx = audioContext();
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
    if (!enabled || !work.busy || work.listening) {
      if (loopTimer.current !== null) window.clearTimeout(loopTimer.current);
      loopTimer.current = null;
      player.stop('working');
      return;
    }
    const startedAt = performance.now();
    loopTimer.current = window.setTimeout(() => {
      loopTimer.current = null;
      if (loopSounds({ busy: work.busy, speaking: work.speaking, sinceMs: performance.now() - startedAt })) player.play('working');
    }, CUES.loopAfterMs);

    return () => {
      if (loopTimer.current !== null) window.clearTimeout(loopTimer.current);
      loopTimer.current = null;
    };
  }, [enabled, player, work.busy, work.listening]);

  const play = useCallback(
    (cue: Exclude<Cue, 'working' | 'mic' | 'opening'>) => {
      if (!enabled || work.listening) return;
      const out = cueBurstReduce(burst.current, { type: 'want', cue, at: performance.now() });
      burst.current = out.state;
      if (out.fire) player.play(out.fire);
    },
    [enabled, player, work.listening],
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
