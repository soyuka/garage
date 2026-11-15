import { safeHref } from './safeHref';

describe('safeHref', () => {
  it('keeps an http(s) URL', () => {
    expect(safeHref('https://www.forum-peugeot.com/x')).toBe('https://www.forum-peugeot.com/x');
    expect(safeHref('HTTP://example.com')).toBe('HTTP://example.com');
  });

  it('drops every other scheme, so no link is drawn', () => {
    for (const url of ['javascript://x%0Aalert(1)', 'JaVaScRiPt:alert(1)', ' javascript:alert(1)', 'data:text/html,<script>', 'vbscript:x', '//evil.example', '/relative', '', null, undefined]) {
      expect(safeHref(url)).toBeUndefined();
    }
  });
});
