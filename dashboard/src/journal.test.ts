import { describe, expect, it } from 'vitest';
import { failureRef } from './journal';

describe('failureRef', () => {
  it('is the log reference of a failed turn', () => {
    expect(failureRef({ status: 'failed', errorId: '9f2c41ab0e7d3c55' })).toBe('9f2c41ab0e7d3c55');
  });

  it('is absent on a turn that did not fail, or a failure an older server journalled without one', () => {
    expect(failureRef({ status: 'completed', errorId: '9f2c41ab0e7d3c55' })).toBeNull();
    expect(failureRef({ status: 'failed', errorId: null })).toBeNull();
    expect(failureRef({ status: 'failed' })).toBeNull();
    expect(failureRef({ status: 'failed', errorId: '' })).toBeNull();
  });
});
