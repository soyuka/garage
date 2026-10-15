import { foldText } from './search';

describe('foldText', () => {
  it('folds accents, case and apostrophes', () => {
    expect(foldText('Étrier d’Huile')).toBe("etrier d'huile");
  });
});
