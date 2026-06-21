import { describe, expect, it } from 'vitest';
import { safeHref } from './safeHref';

describe('safeHref', () => {
  it('passes through http(s) URLs', () => {
    expect(safeHref('https://motul.com/x')).toBe('https://motul.com/x');
    expect(safeHref('http://example.org')).toBe('http://example.org');
  });

  it('is case- and whitespace-insensitive on the scheme', () => {
    expect(safeHref('  HTTPS://motul.com  ')).toBe('  HTTPS://motul.com  ');
  });

  it('rejects javascript: URLs (the XSS vector)', () => {
    expect(safeHref('javascript:alert(1)')).toBeUndefined();
    expect(safeHref('JavaScript:alert(1)')).toBeUndefined();
    expect(safeHref(' javascript:alert(1)')).toBeUndefined();
  });

  it('rejects data:, vbscript: and other non-http schemes', () => {
    expect(safeHref('data:text/html,<script>alert(1)</script>')).toBeUndefined();
    expect(safeHref('vbscript:msgbox(1)')).toBeUndefined();
    expect(safeHref('file:///etc/passwd')).toBeUndefined();
  });

  it('rejects empty / undefined input', () => {
    expect(safeHref(undefined)).toBeUndefined();
    expect(safeHref('')).toBeUndefined();
  });
});
