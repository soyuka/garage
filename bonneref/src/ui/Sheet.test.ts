import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { Sheet } from './Sheet';

const base = { viewLabel: 'ROTULE AXIALE', dimensions: [], ghost: false, reducedMotion: true, placeholder: null, overlayWidth: null };

describe('Sheet without a 3D model', () => {
  it('never renders a figure, only the pictogram or the « ? »', () => {
    const html = renderToStaticMarkup(createElement(Sheet, { ...base, family: { kind: 'generic' } }));
    expect(html).not.toContain('<figure');
    expect(html).not.toContain('sheet-photo');
    expect(html).toContain('sheet-unknown');
  });

  it('draws a plain « ? » without fake geometry', () => {
    const html = renderToStaticMarkup(createElement(Sheet, { ...base, family: { kind: 'generic' } }));
    expect(html).toContain('<div class="sheet-unknown"');
    expect(html).toContain('>?</span>');
    expect(html).toContain('aria-label="Pas de modèle 3D"');
    expect(html).not.toContain('sheet-unknown-cap');
    expect(html).not.toContain('<figure');
  });

  it('draws the family pictogram, uncaptioned, when a rule matches', () => {
    const html = renderToStaticMarkup(createElement(Sheet, { ...base, family: { kind: 'generic' }, categoryPath: 'Système électrique > Démarreur > Charbons' }));
    expect(html).toContain('class="sheet-picto"');
    expect(html).toContain('role="img" aria-label="Démarrage et charge"');
    expect(html).toContain('<svg');
    expect(html).not.toMatch(/pas la pièce|Famille|sheet-picto-cap/);
    expect(html).not.toContain('sheet-unknown');
    expect(html).not.toContain('Glisser pour tourner');
  });

  it('keeps the « ? » when no pictogram rule matches', () => {
    const html = renderToStaticMarkup(createElement(Sheet, { ...base, family: { kind: 'generic' }, categoryPath: 'Zorglub > Truc' }));
    expect(html).toContain('<div class="sheet-unknown"');
    expect(html).not.toContain('sheet-picto');
  });

  it('turns the loading carousel while the part is still being narrowed, whatever its family', () => {
    for (const family of [{ kind: 'generic' } as const, { kind: 'none' } as const]) {
      const html = renderToStaticMarkup(createElement(Sheet, { ...base, family, ghost: true, searching: true, status: 'Affinage des références…' }));
      expect(html).toContain('sheet-canvas');
      expect(html).toContain('Affinage des références…');
      expect(html).not.toContain('sheet-unknown');
      expect(html).not.toContain('<figure');
      expect(html).not.toContain('Glisser pour tourner');
    }
  });

  it('still answers « ? » for a part that will never have a cluster (a web find)', () => {
    const html = renderToStaticMarkup(createElement(Sheet, { ...base, family: { kind: 'generic' }, ghost: true }));
    expect(html).toContain('sheet-unknown');
  });
});

describe('Sheet title while the loading carousel runs', () => {
  const meshFamily = { kind: 'mesh' } as never;

  it('hides the drawing title while searching, keeps the status line', () => {
    const html = renderToStaticMarkup(createElement(Sheet, { ...base, viewLabel: 'FILTRE À HUILE', family: meshFamily, searching: true, status: 'Affinage des références…' }));
    expect(html).not.toContain('sheet-label');
    expect(html).not.toContain('FILTRE À HUILE');
    expect(html).toContain('Affinage des références…');
  });

  it('shows the title once the result is there', () => {
    const html = renderToStaticMarkup(createElement(Sheet, { ...base, viewLabel: 'FILTRE À HUILE', family: meshFamily, searching: false }));
    expect(html).toContain('<div class="sheet-label m">FILTRE À HUILE</div>');
    expect(html).not.toContain('Glisser pour tourner');
  });
});

describe('Sheet with an approximate mesh', () => {
  it('prints no caption about the shape being a family stand-in', () => {
    const html = renderToStaticMarkup(createElement(Sheet, { ...base, family: { kind: 'mesh', mesh: 'outer-tie-rod-end', approximate: true } }));
    expect(html).not.toMatch(/Forme type|pas la pièce|sheet-caption/);
  });
});
