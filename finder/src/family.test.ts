import { partFamily } from './family';

const cat = (path: string) => ({ path, name: '' });

describe('partFamily', () => {
  it('maps brakes to a front or rear mesh from the path', () => {
    expect(partFamily(cat('Dispositif de freinage > Frein à disque > Disque de frein avant'))).toEqual({ kind: 'brake', axle: 'front' });
    expect(partFamily(cat('Dispositif de freinage > Frein à disque > Plaquette de frein arrière'))).toEqual({ kind: 'brake', axle: 'rear' });
    expect(partFamily(cat("Dispositif de freinage > Étrier de frein > Étrier de frein / support d'étrier"))).toEqual({ kind: 'brake', axle: 'front' });
  });

  it('reads the axle from the selected part when the path does not say', () => {
    const dims = [{ key: 'cote_d_assemblage', label: 'Côté d’assemblage', value: 'Essieu arrière', number: null, unit: null, min: null, max: null, info: false }];
    expect(partFamily(cat('Dispositif de freinage > Frein à disque > Disque de frein'), dims)).toEqual({ kind: 'brake', axle: 'rear' });
  });

  it('maps an oil filter to the parametric filter', () => {
    expect(partFamily(cat('Filtre > Filtre à huile'))).toEqual({ kind: 'filter', filter: 'oil' });
  });

  it('maps fuel and air filters only once they are known to be cylindrical', () => {
    const round = [{ key: 'diametre_exterieur', label: 'Diamètre extérieur', value: '80', number: 80, unit: 'mm', min: 80, max: 80, info: false }];
    expect(partFamily(cat('Filtre > Filtre à air'))).toEqual({ kind: 'none' });
    expect(partFamily(cat('Filtre > Filtre à air'), round)).toEqual({ kind: 'filter', filter: 'air' });
    expect(partFamily(cat('Filtre > Filtre à carburant'), round)).toEqual({ kind: 'filter', filter: 'fuel' });
    expect(partFamily(cat('Filtre > Filtre à air pour habitacle'), round)).toEqual({ kind: 'none' });
  });

  it('leaves everything else unmapped', () => {
    expect(partFamily(cat('Accessoires > Chaîne à neige'))).toEqual({ kind: 'none' });
    expect(partFamily(cat('Dispositif de freinage > Liquide de frein'))).toEqual({ kind: 'none' });
  });
});
