import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { CopyRef } from './CopyRef';

describe('CopyRef', () => {
  const html = renderToStaticMarkup(createElement(CopyRef, { text: '3812 E0' }));

  it('is a real button named after the reference it copies', () => {
    expect(html).toMatch(/^<span class="copy-ref"><button type="button" class="copy-btn" aria-label="Copier 3812 E0"/);
  });

  it('announces the copy in a polite live region, empty until it happens', () => {
    expect(html).toContain('aria-live="polite"');
    expect(html).not.toContain('Copié<');
  });
});
