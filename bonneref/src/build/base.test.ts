import { viteBase } from './base';

describe('viteBase', () => {
  it('serves the dev server under /bonneref/ behind the mpa Caddy', () => {
    expect(viteBase('serve', undefined)).toBe('/bonneref/');
  });

  it('builds for the site root, bonneref.fr serves dist/ at /', () => {
    expect(viteBase('build', undefined)).toBe('/');
  });

  it('lets BONNEREF_BASE override both', () => {
    expect(viteBase('build', '/x/')).toBe('/x/');
    expect(viteBase('serve', '/')).toBe('/');
  });
});
