import { filterScale } from './filterScale';
import type { Dimension } from '../types';

const dim = (key: string, value: string, min = Number(value), max = Number(value)): Dimension => ({ key, label: key, value, number: Number(value), unit: 'mm', min, max, info: false });

const mesh = { x: 69.8, y: 69, z: 69.4 };

describe('filterScale', () => {
  it('scales Y from the height and XZ from the outer diameter, against the measured mesh', () => {
    const s = filterScale(mesh, [dim('diametre_exterieur', '76'), dim('hauteur', '93')]);
    expect(s.y).toBeCloseTo(93 / 69, 6);
    expect(s.x).toBeCloseTo(76 / 69.8, 6);
    expect(s.z).toBe(s.x);
    expect(s.height).toEqual({ mid: 93, text: '93' });
    expect(s.diameter).toEqual({ mid: 76, text: '76' });
  });

  it('scales to the midpoint of a range and labels it with the range, like the title block', () => {
    const s = filterScale(mesh, [dim('diametre_exterieur', '76', 75, 78), dim('hauteur', '93', 85, 94)]);
    expect(s.y).toBeCloseTo(89.5 / 69, 6);
    expect(s.x).toBeCloseTo(76.5 / 69.8, 6);
    expect(s.height).toEqual({ mid: 89.5, text: '85–94' });
    expect(s.diameter).toEqual({ mid: 76.5, text: '75–78' });
  });

  it('makes the 85 and 93 mm clusters visibly different', () => {
    const tall = filterScale(mesh, [dim('hauteur', '93')]);
    const short = filterScale(mesh, [dim('hauteur', '85')]);
    expect(tall.y * mesh.y - short.y * mesh.y).toBeCloseTo(8, 6);
  });

  it('leaves a missing dimension at 1 and reports it as null', () => {
    const s = filterScale(mesh, [dim('hauteur', '93')]);
    expect(s.x).toBe(1);
    expect(s.diameter).toBeNull();
    expect(filterScale(mesh, [])).toEqual({ x: 1, y: 1, z: 1, height: null, diameter: null });
  });

  it('ignores non-positive or non-numeric values and accepts diametre_1', () => {
    const bad: Dimension = { key: 'hauteur', label: 'Hauteur', value: 'n/a', number: null, unit: null, min: null, max: null, info: false };
    expect(filterScale(mesh, [bad, dim('diametre_1', '0')]).y).toBe(1);
    expect(filterScale(mesh, [dim('diametre_1', '80')]).x).toBeCloseTo(80 / 69.8, 6);
  });

  it('survives a degenerate mesh size', () => {
    expect(filterScale({ x: 0, y: 0, z: 0 }, [dim('hauteur', '93')]).y).toBe(1);
  });
});
