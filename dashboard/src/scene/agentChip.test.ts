import { describe, expect, it } from 'vitest';
import { agentChipState } from './agentChip';

// An agent chip is HIDDEN when
// its data prerequisite is absent (never a dead control) and DISABLED (not queued) while anything is
// already working — the one-driver-at-a-time rule the composer and deterministic chips share.

describe('agentChipState — the summarise-spending chip gate', () => {
  it('hides the chip when there is nothing to summarise', () => {
    expect(agentChipState(0, false)).toEqual({ visible: false, disabled: false });
  });

  it('shows it enabled once at least one visit is logged and nothing is working', () => {
    expect(agentChipState(1, false)).toEqual({ visible: true, disabled: false });
  });

  it('keeps it visible but disabled while busy — disabled, not queued', () => {
    expect(agentChipState(3, true)).toEqual({ visible: true, disabled: true });
  });
});
