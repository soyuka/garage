import { describe, expect, it, vi } from 'vitest';
import { CUES } from '../tokens/audition';
import { CuePlayback, type CueBackend, type PlayingCue } from './cuePlayback';

function harness() {
  const active: PlayingCue[] = [];
  const backend: CueBackend = {
    load: vi.fn(async () => true),
    start: vi.fn((_cue, gain) => {
      let finish!: () => void;
      const playing: PlayingCue = {
        ended: new Promise<void>((resolve) => (finish = resolve)),
        setGain: vi.fn(),
        stop: vi.fn(() => finish()),
      };
      active.push(playing);
      playing.setGain(gain);

      return playing;
    }),
  };

  return { active, backend, player: new CuePlayback(backend) };
}

describe('cue playback lifecycle', () => {
  it('preloads once and drops an event whose asset is not ready', async () => {
    const { backend, player } = harness();
    expect(player.play('panel')).toBe(false);
    await player.preload();
    expect(player.play('panel')).toBe(true);
    await player.preload();
    expect(backend.load).toHaveBeenCalledTimes(CUES.names.length);
  });

  it('ducks active and new one-shots during speech', async () => {
    const { active, player } = harness();
    await player.preload();
    player.play('reveal');
    player.setMode('speech');
    expect(active[0].setGain).toHaveBeenLastCalledWith(CUES.gain.reveal * CUES.speechDuck);
    player.play('panel');
    expect(active[1].setGain).toHaveBeenLastCalledWith(CUES.gain.panel * CUES.speechDuck);
    player.play('opening');
    expect(active[2].setGain).toHaveBeenLastCalledWith(CUES.gain.opening * CUES.speechDuck);
  });

  it('thins the waiting loop without touching the one-shots', async () => {
    const { active, player } = harness();
    await player.preload();
    player.play('working');
    player.play('select');
    player.setLoopScale(0.5);
    expect(active[0].setGain).toHaveBeenLastCalledWith(CUES.loopGain * 0.5);
    expect(active[1].setGain).toHaveBeenLastCalledWith(CUES.gain.select);
    player.setMode('speech');
    expect(active[0].setGain).toHaveBeenLastCalledWith(CUES.loopGain * 0.5 * CUES.speechDuck);
  });

  it('cancels active cues for the microphone and rejects normal cues while it is armed', async () => {
    const { active, player } = harness();
    await player.preload();
    player.play('reveal');
    player.setMode('mic');
    expect(active[0].stop).toHaveBeenCalledOnce();
    expect(player.play('panel')).toBe(false);
  });

  it('invalidates a load completion after teardown', async () => {
    let resolve!: (loaded: boolean) => void;
    const deferred = new Promise<boolean>((done) => (resolve = done));
    const backend: CueBackend = {
      load: vi.fn(() => deferred),
      start: vi.fn(),
    };
    const player = new CuePlayback(backend);
    const pending = player.preload();
    player.destroy();
    resolve(true);
    await pending;
    expect(player.play('opening')).toBe(false);
    expect(backend.start).not.toHaveBeenCalled();
  });

  it('keeps a pending preload valid across microphone suppression', async () => {
    let resolve!: (loaded: boolean) => void;
    const deferred = new Promise<boolean>((done) => (resolve = done));
    const backend: CueBackend = { load: vi.fn(() => deferred), start: vi.fn(() => null) };
    const player = new CuePlayback(backend);
    const pending = player.preload();
    player.setMode('mic');
    resolve(true);
    await pending;
    player.setMode('clear');
    player.play('opening');
    expect(backend.start).toHaveBeenCalledWith('opening', CUES.gain.opening, false);
  });

  it('can bind the opening to an unlock while preload finishes', async () => {
    let resolve!: (loaded: boolean) => void;
    const deferred = new Promise<boolean>((done) => (resolve = done));
    const backend: CueBackend = { load: vi.fn(() => deferred), start: vi.fn(() => null) };
    const player = new CuePlayback(backend);
    const opening = player.playWhenReady('opening', 650);
    resolve(true);
    expect(await opening).toBe(false);
    expect(backend.start).toHaveBeenCalledWith('opening', CUES.gain.opening, false);
  });

  it('drops an unlocked opening when navigation cancels it before preload finishes', async () => {
    let resolve!: (loaded: boolean) => void;
    const deferred = new Promise<boolean>((done) => (resolve = done));
    const backend: CueBackend = { load: vi.fn(() => deferred), start: vi.fn() };
    const player = new CuePlayback(backend);
    const controller = new AbortController();
    const opening = player.playWhenReady('opening', 650, controller.signal);
    controller.abort();
    resolve(true);
    expect(await opening).toBe(false);
    expect(backend.start).not.toHaveBeenCalled();
  });

  it('does not start a mic cue after its arming was cancelled during loading', async () => {
    let resolve!: (loaded: boolean) => void;
    const deferred = new Promise<boolean>((done) => (resolve = done));
    const backend: CueBackend = { load: vi.fn(() => deferred), start: vi.fn() };
    const player = new CuePlayback(backend);
    player.setMode('mic');
    const controller = new AbortController();
    const pending = player.playMic(650, controller.signal);
    controller.abort();
    resolve(true);
    await pending;
    expect(backend.start).not.toHaveBeenCalled();
  });

  it('keeps the working loop alive and ducks it while speech starts', async () => {
    const { active, player } = harness();
    await player.preload();
    player.play('working');
    player.setMode('speech');
    expect(active[0].stop).not.toHaveBeenCalled();
    expect(active[0].setGain).toHaveBeenLastCalledWith(CUES.loopGain * CUES.speechDuck);
    player.setMode('clear');
    expect(active[0].setGain).toHaveBeenLastCalledWith(CUES.loopGain);
  });

  it('waits for the mic source to stop before releasing capture', async () => {
    vi.useFakeTimers();
    let finish!: () => void;
    const playing: PlayingCue = {
      ended: new Promise<void>((resolve) => (finish = resolve)),
      setGain: vi.fn(),
      stop: vi.fn(),
    };
    const backend: CueBackend = { load: vi.fn(async () => true), start: vi.fn(() => playing) };
    const player = new CuePlayback(backend);
    await player.preload();
    player.setMode('mic');
    let settled = false;
    const pending = player.playMic(650).then(() => (settled = true));
    await vi.advanceTimersByTimeAsync(650);
    expect(playing.stop).toHaveBeenCalledOnce();
    expect(settled).toBe(false);
    finish();
    await pending;
    expect(settled).toBe(true);
    vi.useRealTimers();
  });
});
