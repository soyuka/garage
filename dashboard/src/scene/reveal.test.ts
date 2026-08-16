import { describe, it, expect } from 'vitest';
import { revealFor, focusForReveal, revealSurvives } from './reveal';
import { BODY_REGIONS } from './anchors';

describe('revealFor — a reveal declines rather than emptying the stage', () => {
  it('produces a reveal for every region that has a diagram', () => {
    for (const region of BODY_REGIONS) {
      expect(revealFor(region, [], 1)).not.toBeNull();
    }
  });

  it('carries the named parts through as callouts', () => {
    expect(revealFor('bay', ['fuel_filter'], 7)).toEqual({ region: 'bay', partKeys: ['fuel_filter'], key: 7 });
  });

  it('defaults to no callouts when the frame names none — the circuit alone is a valid answer', () => {
    expect(revealFor('hood', undefined, 2)?.partKeys).toEqual([]);
  });
});

describe('focusForReveal — a reveal is a hold, in the reveal pose family', () => {
  it('holds the reveal’s own region and key, so the burst coalescer sees one gesture', () => {
    const reveal = { region: 'bay' as const, partKeys: [], key: 4 };
    expect(focusForReveal(reveal)).toEqual({ region: 'bay', key: 4, mode: 'reveal' });
  });
});

describe('revealSurvives — the diagram is anchored to its hold', () => {
  const reveal = { region: 'bay' as const, partKeys: [], key: 4 };

  it('survives while the camera holds exactly it', () => {
    expect(revealSurvives(reveal, { region: 'bay', key: 4, mode: 'reveal' })).toBe(true);
  });

  it('clears when the camera is released (vue libre, Escape, a drag)', () => {
    expect(revealSurvives(reveal, null)).toBe(false);
  });

  it('clears when the camera is sent to another region', () => {
    expect(revealSurvives(reveal, { region: 'hood', key: 5, mode: 'reveal' })).toBe(false);
  });

  it('clears when the same region is re-held as a plain focus — a focus is not a reveal', () => {
    expect(revealSurvives(reveal, { region: 'bay', key: 4 })).toBe(false);
  });

  it('clears when the same region is revealed again — the new key owns the diagram', () => {
    expect(revealSurvives(reveal, { region: 'bay', key: 9, mode: 'reveal' })).toBe(false);
  });

  it('is false with no reveal at all', () => {
    expect(revealSurvives(null, { region: 'bay', key: 4, mode: 'reveal' })).toBe(false);
  });
});
