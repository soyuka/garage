import { afterEach, describe, expect, it, vi } from 'vitest';
import { RecordingCancelled, record } from './recorder';

class FakeMediaRecorder {
  static isTypeSupported = () => true;
  static instances: FakeMediaRecorder[] = [];
  readonly mimeType = 'audio/webm';
  state = 'inactive';
  start = vi.fn(() => (this.state = 'recording'));
  stop = vi.fn();
  addEventListener = vi.fn();

  constructor() {
    FakeMediaRecorder.instances.push(this);
  }
}

describe('microphone ready cue ordering', () => {
  afterEach(() => {
    FakeMediaRecorder.instances = [];
    vi.unstubAllGlobals();
  });

  it('finishes the ready phase before capture starts', async () => {
    let finish!: () => void;
    const ready = new Promise<void>((resolve) => (finish = resolve));
    const track = { stop: vi.fn() };
    vi.stubGlobal('navigator', { mediaDevices: { getUserMedia: vi.fn(async () => ({ getTracks: () => [track] })) } });
    vi.stubGlobal('MediaRecorder', FakeMediaRecorder);
    const pending = record(() => ready);
    await Promise.resolve();
    expect(FakeMediaRecorder.instances).toHaveLength(0);
    finish();
    await pending;
    expect(FakeMediaRecorder.instances[0].start).toHaveBeenCalledOnce();
  });

  it('releases the permission stream without starting capture when arming is cancelled', async () => {
    let finish!: () => void;
    const ready = new Promise<void>((resolve) => (finish = resolve));
    const track = { stop: vi.fn() };
    vi.stubGlobal('navigator', { mediaDevices: { getUserMedia: vi.fn(async () => ({ getTracks: () => [track] })) } });
    vi.stubGlobal('MediaRecorder', FakeMediaRecorder);
    const controller = new AbortController();
    const pending = record(() => ready, controller.signal);
    controller.abort();
    finish();
    await expect(pending).rejects.toBeInstanceOf(RecordingCancelled);
    expect(FakeMediaRecorder.instances).toHaveLength(0);
    expect(track.stop).toHaveBeenCalledOnce();
  });
});
