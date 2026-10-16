import { PICTO_IDS, categoryPicto } from './picto';

const SVGS = import.meta.glob<string>('./pictos/*.svg', { query: '?raw', import: 'default', eager: true });
const LIST = import.meta.glob<string>('../missing-list-images.md', { query: '?raw', import: 'default', eager: true });

function missingLeaves(): string[] {
  const text = Object.values(LIST)[0] ?? '';
  const section = text.split('## Missing leaves by branch')[1] ?? '';
  return section
    .split('\n')
    .filter((line) => /^- \d+ /.test(line))
    .map((line) => line.replace(/^- \d+ /, '').replace(/ \[pinned\]$/, '').split(/ → | \(/)[0]!.trim());
}

describe('categoryPicto', () => {
  it('draws a family for every category the catalogue lists without a mesh', () => {
    const paths = missingLeaves();
    expect(paths.length).toBeGreaterThan(300);
    const unmatched = paths.filter((p) => categoryPicto(p) === null);
    expect(unmatched).toEqual([]);
  });

  it.each([
    ['Carrosserie > Arrière de la voiture > Pare-chocs', 'body-rear', 'Arrière du véhicule'],
    ['Carrosserie > Avant du véhicule > Capot', 'body-front', 'Avant du véhicule'],
    ['Carrosserie > Feux > Feu arrière', 'lamp', 'Éclairage'],
    ['Carrosserie > Pièces de carrosserie/ailes/pares-chocs > Aile', 'body-panel', 'Pièces de carrosserie'],
    ['Carrosserie > Habitacle > Siège', 'seat', 'Habitacle'],
    ['Carrosserie > Vitrages/Miroirs > Rétroviseur', 'mirror', 'Vitrages et rétroviseurs'],
    ['Carrosserie > Trappes/capots/portes/toit coul./toit panoram./toit pliant > Porte', 'door', 'Ouvrants'],
    ['Carrosserie > Grilles décoratives, logos, anti-éclaboussures', 'grille', 'Grilles et logos'],
    ['Carrosserie > Fixation de radiateur', 'body', 'Carrosserie'],
    ['Système électrique > Feux > Feu stop', 'lamp', 'Éclairage'],
    ['Système électrique > Projecteurs principaux / composants > Ampoule', 'lamp', 'Éclairage'],
    ['Système électrique > Démarreur > Charbons', 'rotating-machine', 'Démarrage et charge'],
    ['Système électrique > Alternateur / composants', 'rotating-machine', 'Démarrage et charge'],
    ['Système électrique > Instruments > Compteur', 'gauge', 'Instruments'],
    ['Système électrique > Boîte à fusibles / support', 'relay', 'Relais et fusibles'],
    ['Système électrique > Capteurs > Capteur de vitesse', 'electrical', 'Système électrique'],
    ['Moteur > Distribution moteur > Chaîne', 'timing', 'Distribution'],
    ['Moteur > Commande à courroie > Poulie', 'belt', 'Courroies'],
    ['Moteur > Joints > Joint de couvercle', 'gasket', 'Joints'],
    ['Moteur > Culasse / pièces de montage > Joint de culasse', 'gasket', 'Joints'],
    ['Moteur > Culasse / pièces de montage > Soupape', 'cylinder-head', 'Culasse'],
    ['Moteur > Lubrification > Pompe à huile', 'oil', 'Lubrification'],
    ['Moteur > Alimentation en air > Durite', 'air-intake', "Admission d'air"],
    ['Moteur > Système bielle-manivelle > Coussinet', 'piston', 'Embiellage'],
    ['Moteur > Épuration des gaz d’échappement > Vanne EGR', 'egr', 'Recyclage des gaz'],
    ['Moteur > Support du moteur > Silent bloc', 'engine-mount', 'Support moteur'],
    ['Moteur > Cache moteur', 'engine', 'Moteur'],
    ['Commande à courroie > Poulie', 'belt', 'Courroies'],
    ['Carburation > Mélange air-carburant > Injecteur', 'fuel-injection', 'Injection / carburation'],
    ['Boîte de vitesses > Huiles', 'gearbox', 'Boîte de vitesses'],
    ['Échappement > Catalyseur', 'exhaust', 'Échappement'],
    ['Dispositif de freinage > Liquide de frein', 'brake', 'Freinage'],
    ['Direction > Levier de direction', 'steering', 'Direction'],
    ["Suspension d'essieu/Guidage des roues/Roues > Rotules", 'axle', 'Train roulant'],
    ['Suspension/Amortissement > Huiles', 'shock', 'Suspension'],
    ['Entraînement des roues > Soufflet', 'driveshaft', 'Transmission'],
    ['Entraînement des essieux > Huiles', 'driveshaft', 'Transmission'],
    ['Refroidissement > Antigel', 'radiator', 'Refroidissement'],
    ["Embrayage / composants > Volant-moteur", 'clutch', 'Embrayage'],
    ['Allumage / préchauffage > Bougie de préchauffage', 'spark', 'Allumage'],
    ['Alimentation carburant > Tuyauterie', 'fuel', 'Alimentation carburant'],
    ['Climatisation > Condenseur', 'ac', 'Climatisation'],
    ['Chauffage / Ventilation > Gaines/tuyaux', 'fan', 'Chauffage / ventilation'],
    ['Roues/Pneus > Jantes', 'wheel', 'Roues et pneus'],
    ['Nettoyage des vitres > Gicleur d’eau de lavage', 'wiper', 'Nettoyage'],
    ['Nettoyage des phares > ', 'wiper', 'Nettoyage'],
    ['Systèmes de sécurité > Système de ceintures', 'safety', 'Sécurité'],
    ["Système d'information et de communication > Navigation", 'screen', 'Information et communication'],
    ['Systèmes de confort > Lève-vitre', 'comfort', 'Confort'],
    ['Verrouillage > Clés', 'lock', 'Verrouillage'],
    ['Équipement intérieur > Sièges', 'seat', 'Équipement intérieur'],
    ['Accessoires > Tapis', 'accessory', 'Accessoires'],
    ['Portes-bagages > Barres de toit', 'roof-rack', 'Porte-bagages'],
    ["Dispositif d'attelage/accessoires > Composants / pièces de montage", 'tow', 'Attelage'],
    ['Entraînement hybride/électrique > Refroidissement', 'ev', 'Hybride / électrique'],
    ['Système pneumatique > Interrupteur', 'pneumatic', 'Système pneumatique'],
    ['Pièces de maintenance > Intervalle', 'service', 'Entretien'],
    ['Filtre > Filtre hydraulique', 'filter', 'Filtres'],
    ['Outils spéciaux > Outils spéciaux suspension', 'tool', 'Outils'],
  ])('%s → %s', (path, id, label) => {
    expect(categoryPicto(path)).toEqual({ id, label });
  });

  it('lets a sub-branch rule beat its branch rule', () => {
    expect(categoryPicto('Carrosserie > Arrière de la voiture > Feu')?.id).toBe('body-rear');
    expect(categoryPicto('Système électrique > Feux > Ampoule')?.id).toBe('lamp');
    expect(categoryPicto('Moteur > Joints > Joint')?.id).toBe('gasket');
  });

  it('returns null for an unknown branch', () => {
    expect(categoryPicto('Zorglub > Truc')).toBeNull();
    expect(categoryPicto('')).toBeNull();
  });
});

describe('pictogram files', () => {
  it('has one SVG per PictoId and no stray SVG', () => {
    const files = Object.keys(SVGS).map((f) => f.replace(/^.*\/|\.svg$/g, ''));
    expect([...files].sort()).toEqual([...PICTO_IDS].sort());
  });

  it('follows the style contract', () => {
    for (const id of PICTO_IDS) {
      const svg = SVGS[`./pictos/${id}.svg`] ?? '';
      expect(svg, id).toContain('viewBox="0 0 120 120"');
      expect(svg, id).toContain('fill="none"');
      expect(svg, id).toContain('stroke="currentColor"');
      expect(svg.match(/<svg[^>]*>/)?.[0], id).not.toMatch(/\swidth=|\sheight=/);
      expect(svg, id).not.toMatch(/<text|<style|gradient|\sid=/);
      const els = svg.match(/<(path|circle|rect|line|polyline|polygon|ellipse)\b[^>]*>/g) ?? [];
      expect(els.length, id).toBeGreaterThanOrEqual(6);
      expect(els.length, id).toBeLessThanOrEqual(25);
      for (const el of els) expect(el, id).toContain('vector-effect="non-scaling-stroke"');
    }
  });
});
