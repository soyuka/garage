import { describe, expect, it } from 'vitest';
import { safeHref } from './safeHref';

// A fact's url is agent/web-search-influenced → untrusted. Only http(s) may reach an href, or a
// javascript:/data: URL would run script on click (XSS). Re-authored fresh from the contract.

describe('safeHref', () => {
  it('passes an http url through', () => {
    expect(safeHref('http://example.com')).toBe('http://example.com');
  });
  it('passes an https url through', () => {
    expect(safeHref('https://example.com/spec?a=1')).toBe('https://example.com/spec?a=1');
  });
  it('blocks a javascript: scheme', () => {
    expect(safeHref('javascript:alert(1)')).toBeUndefined();
  });
  it('blocks a data: scheme', () => {
    expect(safeHref('data:text/html,<script>1</script>')).toBeUndefined();
  });
  it('blocks a relative path (no scheme)', () => {
    expect(safeHref('/garage/vehicles/1')).toBeUndefined();
  });
  it('null / undefined / empty → undefined', () => {
    expect(safeHref(null)).toBeUndefined();
    expect(safeHref(undefined)).toBeUndefined();
    expect(safeHref('')).toBeUndefined();
  });
  it('tolerates leading whitespace before a valid scheme', () => {
    expect(safeHref('  https://example.com')).toBe('  https://example.com');
  });
  it('is case-insensitive on the scheme', () => {
    expect(safeHref('HTTPS://example.com')).toBe('HTTPS://example.com');
  });
});
