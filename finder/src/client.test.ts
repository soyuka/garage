import * as client from './client';
import { traced } from './client';

describe('client module', () => {
  it('serves the live API only, no recorded mode', () => {
    expect(Object.keys(client)).not.toContain('isDemo');
    expect(Object.keys(client)).toContain('liveApi');
  });
});

describe('traced', () => {
  it('journals the rule and the typed text, and the plate the page knows', () => {
    const h = new Headers(traced({ rule: 'history', input: 'filtre à huile', plate: 'XB-301-ZB' }).headers);
    expect(h.get('X-Finder-Rule')).toBe('history');
    expect(h.get('X-Finder-Input')).toBe(encodeURIComponent('filtre à huile'));
    expect(h.get('X-Finder-Plate')).toBe('XB-301-ZB');
  });

  it('sends no plate header without a plate, and nothing without a trace', () => {
    expect(new Headers(traced({ rule: 'url', input: 'x' }).headers).has('X-Finder-Plate')).toBe(false);
    expect(new Headers(traced().headers).has('X-Finder-Rule')).toBe(false);
  });
});
