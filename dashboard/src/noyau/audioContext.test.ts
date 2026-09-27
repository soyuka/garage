import { describe, it, expect, beforeEach, vi } from 'vitest';
import { audioContext, holdAudio, releaseAudio, resumeAudioFromGesture, suspendIfIdle, __resetAudio } from './audioContext';

class FakeContext {
  state: 'running' | 'suspended' | 'closed' = 'running';
  resume = vi.fn(async (): Promise<void> => {
    this.state = 'running';
  });
  async suspend(): Promise<void> {
    this.state = 'suspended';
  }
  async close(): Promise<void> {
    this.state = 'closed';
  }
}

beforeEach(() => {
  __resetAudio();
  vi.stubGlobal('AudioContext', FakeContext);
});

describe('the shared audio context', () => {
  it('is one context for the tab, however many callers ask', () => {
    expect(audioContext()).toBe(audioContext());
  });

  it('calls resume during the gesture and unlocks only after the context is running', async () => {
    const ctx = audioContext() as unknown as FakeContext;
    ctx.state = 'suspended';
    let resolveResume!: () => void;
    ctx.resume.mockImplementationOnce(() => new Promise<void>((resolve) => (resolveResume = resolve)));

    const unlocked = resumeAudioFromGesture();
    expect(ctx.resume).toHaveBeenCalledOnce();
    resolveResume();

    await expect(unlocked).resolves.toBe(false);
  });

  it('unlocks after a gesture resume leaves the context running', async () => {
    const ctx = audioContext() as unknown as FakeContext;
    ctx.state = 'suspended';

    await expect(resumeAudioFromGesture()).resolves.toBe(true);
  });

  it('suspends when nothing holds it', async () => {
    const ctx = audioContext() as unknown as FakeContext;
    await suspendIfIdle();
    expect(ctx.state).toBe('suspended');
  });

  it('refuses to suspend while the bed holds it', async () => {
    const ctx = audioContext() as unknown as FakeContext;
    holdAudio();
    await suspendIfIdle();
    expect(ctx.state).toBe('running');

    releaseAudio();
    await suspendIfIdle();
    expect(ctx.state).toBe('suspended');
  });

  it('counts holds rather than latching, so two holders cannot release each other', async () => {
    const ctx = audioContext() as unknown as FakeContext;
    holdAudio();
    holdAudio();
    releaseAudio();
    await suspendIfIdle();
    expect(ctx.state).toBe('running');
  });

  it('never drops below zero holds, so a stray release cannot pin the context awake', async () => {
    const ctx = audioContext() as unknown as FakeContext;
    releaseAudio();
    releaseAudio();
    holdAudio();
    releaseAudio();
    await suspendIfIdle();
    expect(ctx.state).toBe('suspended');
  });
});
