import { describe, it, expect } from 'vitest';
import { stageEnabled } from './stage';

describe('stageEnabled — the URL is the rehearsal switch, storage is the armed kiosk', () => {
  it('is off by default', () => {
    expect(stageEnabled('', null)).toBe(false);
    expect(stageEnabled('?v=1', null)).toBe(false);
  });

  it('turns on from the URL', () => {
    expect(stageEnabled('?stage=1', null)).toBe(true);
    expect(stageEnabled('?stage', null)).toBe(true);
    expect(stageEnabled('?stage=true', null)).toBe(true);
  });

  it('turns on from storage — how `mpa stage up` arms the kiosk across reloads', () => {
    expect(stageEnabled('', '1')).toBe(true);
    expect(stageEnabled('', 'true')).toBe(true);
  });

  it('lets ?stage=0 override an armed laptop, so a desk session needs no storage surgery', () => {
    expect(stageEnabled('?stage=0', '1')).toBe(false);
    expect(stageEnabled('?stage=off', '1')).toBe(false);
  });

  it('ignores a stored value that is not an explicit yes', () => {
    expect(stageEnabled('', '0')).toBe(false);
    expect(stageEnabled('', 'maybe')).toBe(false);
  });
});
