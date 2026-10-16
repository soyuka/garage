import { routeHint } from './Intake';

describe('routeHint', () => {
  it('names what the input was read as, in nouns', () => {
    expect(routeHint({ kind: 'plate', plate: 'AB-123-CD', format: 'siv' }, false)).toBe('Plaque SIV');
    expect(routeHint({ kind: 'plate', plate: '1234 AB 56', format: 'fni' }, false)).toBe('Plaque FNI (ancien format)');
    expect(routeHint({ kind: 'reference', reference: 'W 712/73' }, true)).toBe('Référence de pièce');
    expect(routeHint({ kind: 'text', text: 'freins qui grincent' }, true)).toBe('Recherche libre');
  });

  it('asks for the vehicle on a free search without one, and says nothing when empty', () => {
    expect(routeHint({ kind: 'text', text: 'filtre à huile' }, false)).toBe('Recherche libre : précisez marque, modèle et moteur.');
    expect(routeHint({ kind: 'empty' }, true)).toBe('');
  });
});
