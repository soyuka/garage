import { describe, it, expect } from 'vitest';
import { legendFocusReducer, cursorEnter, cursorLeave, cursorHoverCount, type LegendFocus } from './picking';

describe('legendFocusReducer', () => {
  it('a pick sets the focus', () => {
    const next = legendFocusReducer(null, { type: 'pick', kind: 'visit', key: 'v1' });
    expect(next).toEqual({ kind: 'visit', key: 'v1' });
  });

  it('a pick replaces a standing focus of the same kind', () => {
    const state: LegendFocus = { kind: 'visit', key: 'v1' };
    const next = legendFocusReducer(state, { type: 'pick', kind: 'visit', key: 'v2' });
    expect(next).toEqual({ kind: 'visit', key: 'v2' });
  });

  it('a pick can steal the focus from a different kind (only one station is ever hovered at once)', () => {
    const state: LegendFocus = { kind: 'fact', key: 'f1' };
    const next = legendFocusReducer(state, { type: 'pick', kind: 'fluid', key: 'oil' });
    expect(next).toEqual({ kind: 'fluid', key: 'oil' });
  });

  it('hover(null) clears a focus owned by the same kind — the pointer left the picked body', () => {
    const state: LegendFocus = { kind: 'fluid', key: 'oil' };
    const next = legendFocusReducer(state, { type: 'hover', kind: 'fluid', key: null });
    expect(next).toBeNull();
  });

  it('hover(null) from a DIFFERENT kind leaves another station\'s focus alone', () => {
    const state: LegendFocus = { kind: 'fluid', key: 'oil' };
    const next = legendFocusReducer(state, { type: 'hover', kind: 'visit', key: null });
    expect(next).toEqual(state);
  });

  it('hover with a non-null key never reassigns the focus — only a click does that', () => {
    const state: LegendFocus = { kind: 'visit', key: 'v1' };
    const next = legendFocusReducer(state, { type: 'hover', kind: 'visit', key: 'v2' });
    expect(next).toEqual(state);
  });

  it('hover with a non-null key is a no-op with no standing focus', () => {
    const next = legendFocusReducer(null, { type: 'hover', kind: 'visit', key: 'v1' });
    expect(next).toBeNull();
  });

  it('leave clears a focus owned by the same kind', () => {
    const state: LegendFocus = { kind: 'fact', key: 'f1' };
    const next = legendFocusReducer(state, { type: 'leave', kind: 'fact' });
    expect(next).toBeNull();
  });

  it('leave from a different kind is a no-op', () => {
    const state: LegendFocus = { kind: 'fact', key: 'f1' };
    const next = legendFocusReducer(state, { type: 'leave', kind: 'visit' });
    expect(next).toEqual(state);
  });
});

describe('cursor ref-count', () => {
  it('goes to 1 on the first enter', () => {
    cursorEnter();
    expect(cursorHoverCount()).toBe(1);
    cursorLeave();
  });

  it('two overlapping bodies do not flicker the cursor: the count clears only on the LAST leave', () => {
    cursorEnter(); // body A
    cursorEnter(); // body B, still overlapping A
    cursorLeave(); // A leaves — B still hovered
    expect(cursorHoverCount()).toBe(1);
    cursorLeave(); // B leaves — now cleared
    expect(cursorHoverCount()).toBe(0);
  });

  it('never goes negative on an unbalanced leave', () => {
    cursorLeave();
    cursorLeave();
    expect(cursorHoverCount()).toBe(0);
    cursorEnter();
    expect(cursorHoverCount()).toBe(1);
    cursorLeave();
    expect(cursorHoverCount()).toBe(0);
  });
});
