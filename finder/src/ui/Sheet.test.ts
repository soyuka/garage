import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { Sheet } from './Sheet';

const base = { viewLabel: 'ROTULE AXIALE', dimensions: [], ghost: false, reducedMotion: true, placeholder: null, overlayWidth: null };
const photo = { src: '/cars/16182/part-categories/100198/articles/112767557/photo?carType=PC', reference: 'BOSCH 0 986 141 133', alt: 'Photo catalogue de la pièce BOSCH 0 986 141 133' };

describe('Sheet without a 3D model', () => {
  it('pins the catalogue photo on the sheet, captioned, with no drag hint and no canvas', () => {
    const html = renderToStaticMarkup(createElement(Sheet, { ...base, family: { kind: 'photo' }, photo }));
    expect(html).toContain('<figure class="sheet-photo">');
    expect(html).toContain(`src="${photo.src.replace(/&/g, '&amp;')}"`);
    expect(html).toContain('<figcaption class="sheet-photo-cap m">Photo catalogue · <span class="nw">BOSCH 0 986 141 133</span></figcaption>');
    expect(html).not.toContain('Glisser pour tourner');
    expect(html).not.toContain('sheet-canvas');
    expect(html).not.toContain('forme générique');
  });

  it('draws a plain « ? » when there is no photo, without fake geometry', () => {
    const html = renderToStaticMarkup(createElement(Sheet, { ...base, family: { kind: 'photo' }, photo: null }));
    expect(html).toContain('<div class="sheet-unknown"');
    expect(html).toContain('>?</span>');
    expect(html).toContain('aria-label="Ni modèle 3D ni photo catalogue"');
    expect(html).not.toContain('sheet-unknown-cap');
    expect(html).not.toContain('<figure');
  });

  it('draws the family pictogram, captioned, when there is no photo but a rule matches', () => {
    const html = renderToStaticMarkup(createElement(Sheet, { ...base, family: { kind: 'photo' }, photo: null, categoryPath: 'Système électrique > Démarreur > Charbons' }));
    expect(html).toContain('class="sheet-picto"');
    expect(html).toContain('role="img" aria-label="Famille : Démarrage et charge, pas la pièce"');
    expect(html).toContain('<svg');
    expect(html).toContain('Famille : Démarrage et charge, pas la pièce');
    expect(html).not.toContain('sheet-unknown');
    expect(html).not.toContain('Glisser pour tourner');
  });

  it('keeps the « ? » when no pictogram rule matches', () => {
    const html = renderToStaticMarkup(createElement(Sheet, { ...base, family: { kind: 'photo' }, photo: null, categoryPath: 'Zorglub > Truc' }));
    expect(html).toContain('<div class="sheet-unknown"');
    expect(html).not.toContain('sheet-picto');
  });

  it('prefers the catalogue photo to the pictogram', () => {
    const html = renderToStaticMarkup(createElement(Sheet, { ...base, family: { kind: 'photo' }, photo, categoryPath: 'Système électrique > Démarreur' }));
    expect(html).toContain('<figure class="sheet-photo">');
    expect(html).not.toContain('sheet-picto');
  });

  it('turns the loading carousel while the part is still being narrowed, whatever its family', () => {
    for (const family of [{ kind: 'photo' } as const, { kind: 'none' } as const]) {
      const html = renderToStaticMarkup(createElement(Sheet, { ...base, family, photo: null, ghost: true, searching: true, status: 'Affinage des références…' }));
      expect(html).toContain('sheet-canvas');
      expect(html).toContain('Affinage des références…');
      expect(html).not.toContain('sheet-unknown');
      expect(html).not.toContain('<figure');
      expect(html).not.toContain('Glisser pour tourner');
    }
  });

  it('still answers « ? » for a part that will never have a cluster (a web find)', () => {
    const html = renderToStaticMarkup(createElement(Sheet, { ...base, family: { kind: 'photo' }, photo: null, ghost: true }));
    expect(html).toContain('sheet-unknown');
  });
});
