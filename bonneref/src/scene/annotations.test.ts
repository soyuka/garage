import { NNBSP } from '../format';
import type { Dimension } from '../types';
import { brakeAnnotations, filterAnnotations } from './annotations';

const d = (key: string, value: string, min: number | null = null, max: number | null = null): Dimension => ({
  key, label: key, value, number: Number.isNaN(Number(value)) ? null : Number(value), unit: 'mm', min, max, info: false,
});

describe('filterAnnotations', () => {
  it('produces no label at all without catalogue data', () => {
    expect(filterAnnotations([])).toEqual({ diameter: null, height: null, thread: null });
  });

  it('never prints an approximate figure', () => {
    const labels = Object.values(filterAnnotations([d('hauteur', '85')]));
    expect(labels.filter((l) => l !== null && l.includes('≈'))).toEqual([]);
  });

  it('labels only the cotes the fingerprint carries', () => {
    expect(filterAnnotations([d('hauteur', '93', 85, 94)])).toEqual({ diameter: null, height: '85–94', thread: null });
    expect(filterAnnotations([d('diametre_exterieur', '76', 75, 78), d('thread', '3/4-16')])).toEqual({ diameter: `Ø${NNBSP}75–78`, height: null, thread: '3/4-16' });
  });
});

describe('brakeAnnotations', () => {
  it('draws nothing for a disc without catalogue cotes', () => {
    expect(brakeAnnotations([], 'disc')).toEqual({ diameter: null, thickness: null });
  });

  it('labels disc diameter and thickness from the fingerprint only', () => {
    expect(brakeAnnotations([d('diametre_exterieur', '266'), d('epaisseur_du_disque_de_frein', '22')], 'disc')).toEqual({ diameter: `Ø${NNBSP}266`, thickness: `ép.${NNBSP}22` });
    expect(brakeAnnotations([d('epaisseur', '20', 20, 22)], 'disc')).toEqual({ diameter: null, thickness: `ép.${NNBSP}20–22` });
  });

  it('never labels a pad or caliper on the disc mesh', () => {
    expect(brakeAnnotations([d('diametre_exterieur', '266'), d('epaisseur', '17')], 'other')).toEqual({ diameter: null, thickness: null });
  });
});
