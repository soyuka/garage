import { describe, it, expect } from 'vitest';
import source from './EntretienRail.tsx?raw';

// Chrome renders `mask-image` wrong on a scrolling element (rail.css `.at-rail` was both). The fix wraps
// every child of the `<aside className="at-rail">` in an inner `.at-rail-scroll` div, so the mask sits on
// a non-scrolling element and `.at-rail-scroll` alone scrolls. Read as text — no renderer needed to pin
// that the wrapper exists and actually encloses the rail's content.

describe('EntretienRail source: the scroller is a real element, not just a class rename', () => {
  it('renders an at-rail-scroll wrapper inside the at-rail aside', () => {
    expect(source).toContain('at-rail-scroll');
    expect(source.indexOf('"at-rail"')).toBeLessThan(source.indexOf('at-rail-scroll'));
  });
  it('closes the scroll wrapper before the aside itself closes', () => {
    const scrollOpen = source.indexOf('at-rail-scroll');
    const asideClose = source.lastIndexOf('</aside>');
    expect(scrollOpen).toBeGreaterThan(-1);
    expect(scrollOpen).toBeLessThan(asideClose);
  });
});
