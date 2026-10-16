import categories from '../test-fixtures/categories.json';
import { MESH_RULES, partFamily } from './family';
import { MESHES, meshFile, type MeshId } from './meshes';

const cat = (path: string) => ({ path, name: '' });

const tree = categories['hydra:member'];
const real = (path: string) => {
  const found = tree.find((c) => c.path === path);
  if (!found) throw new Error(`not in the Mazda tree: ${path}`);
  return { path: found.path, name: found.name };
};

const meshOf = (category: { path: string; name: string }): MeshId | null => {
  const f = partFamily(category);
  return f.kind === 'mesh' ? f.mesh : null;
};

describe('partFamily', () => {
  it('maps brakes to a front or rear mesh from the path', () => {
    expect(partFamily(cat('Dispositif de freinage > Frein à disque > Disque de frein avant'))).toEqual({ kind: 'brake', axle: 'front', component: 'disc' });
    expect(partFamily(cat("Dispositif de freinage > Étrier de frein > Étrier de frein / support d'étrier"))).toEqual({ kind: 'brake', axle: 'front', component: 'other' });
    expect(partFamily(cat('Dispositif de freinage > Frein à disque > Kit de freins arrière'))).toEqual({ kind: 'brake', axle: 'rear', component: 'other' });
  });

  it('reads the axle from the selected part when the path does not say', () => {
    const dims = [{ key: 'cote_d_assemblage', label: 'Côté d’assemblage', value: 'Essieu arrière', number: null, unit: null, min: null, max: null, info: false }];
    expect(partFamily(cat('Dispositif de freinage > Frein à disque > Disque de frein'), dims)).toEqual({ kind: 'brake', axle: 'rear', component: 'disc' });
    expect(partFamily(real('Dispositif de freinage > Frein à disque > Plaquette de frein'), dims)).toEqual({ kind: 'mesh', mesh: 'rear-brake-pad', approximate: true });
  });

  it('maps an oil filter to the parametric filter', () => {
    expect(partFamily(cat('Filtre > Filtre à huile'))).toEqual({ kind: 'filter', filter: 'oil' });
    expect(partFamily(real('Moteur > Lubrification > Filtre à huile'))).toEqual({ kind: 'filter', filter: 'oil' });
  });

  it('scales the filter can for round fuel and air filters, shows their own mesh otherwise', () => {
    const round = [{ key: 'diametre_exterieur', label: 'Diamètre extérieur', value: '80', number: 80, unit: 'mm', min: 80, max: 80, info: false }];
    expect(partFamily(cat('Filtre > Filtre à air'), round)).toEqual({ kind: 'filter', filter: 'air' });
    expect(partFamily(cat('Filtre > Filtre à carburant'), round)).toEqual({ kind: 'filter', filter: 'fuel' });
    expect(meshOf(real('Filtre > Filtre à air'))).toBe('air-filter');
    expect(meshOf(real('Filtre > Filtre à carburant'))).toBe('fuel-filter');
    expect(partFamily(cat('Filtre > Filtre à air pour habitacle'), round)).toEqual({ kind: 'generic' });
  });

  it('maps the real Mazda tree categories to their mesh', () => {
    const expected: [string, MeshId][] = [
      ['Direction > Rotules', 'outer-tie-rod-end'],
      ['Direction > Barre de connexion / composants > Barre de connexion', 'outer-tie-rod-end'],
      ['Direction > Crémaillère de direction/pompe', 'steering-rack'],
      ["Suspension d'essieu/Guidage des roues/Roues > Bras oscillant / suspension > Bras (transversal, longitudinal, oblique)", 'front-lower-control-arm'],
      ["Suspension d'essieu/Guidage des roues/Roues > Bras oscillant / suspension > Fixation/placement", 'lower-control-arm-bushing'],
      ["Suspension d'essieu/Guidage des roues/Roues > Fixation jambe de force / suspension", 'strut-top-mount'],
      ["Suspension d'essieu/Guidage des roues/Roues > Pièce de stabilisateur / pièces de fixation > Barre de suspension", 'sway-bar-end-link'],
      ["Suspension d'essieu/Guidage des roues/Roues > Pièce de stabilisateur / pièces de fixation > Stabilisateur", 'front-sway-bar'],
      ['Suspension/Amortissement > Amortisseurs', 'front-shock-absorber'],
      ["Suspension/Amortissement > Jambe de suspension/Amortisseur/Pièces d'assemblage > Jambe de suspension", 'front-shock-absorber'],
      ["Suspension/Amortissement > Jambe de suspension/Amortisseur/Pièces d'assemblage > Pièces de montage", 'front-shock-bushing'],
      ["Suspension/Amortissement > Coupelle de suspension/d'amortisseur", 'strut-top-mount'],
      ['Suspension/Amortissement > Suspension', 'coil-spring'],
      ['Refroidissement > Joint/Thermostat > Thermostat', 'thermostat'],
      ['Refroidissement > Pompe à eau / joint > Pompe à eau', 'water-pump'],
      ["Refroidissement > Radiateur d'eau/d'huile > Radiateur d'eau/composants", 'radiator'],
      ["Refroidissement > Radiateur d'eau/d'huile > Radiateur d'huile", 'oil-cooler'],
      ["Moteur > Lubrification > Radiateur d'huile / pièces de montage > Radiateur d'huile", 'oil-cooler'],
      ['Refroidissement > Tuyauterie et brides > Durite de refroidissement/conduite', 'upper-radiator-hose'],
      ['Refroidissement > Ventilateur', 'cooling-fan'],
      ['Refroidissement > Interrupteur / capteur', 'coolant-temperature-sensor'],
      ['Moteur > Distribution moteur > Courroie crantée/tension/guidage > Courroie crantée', 'timing-belt'],
      ['Moteur > Distribution moteur > Courroie crantée/tension/guidage > Poulie de tension', 'tensioner-pulley'],
      ['Moteur > Distribution moteur > Courroie crantée/tension/guidage > Poulie de renvoi/poulie de guidage', 'idler-pulley'],
      ['Commande à courroie > Jeu/ courroies trapézoïdales à nervures > Bras tendeur', 'tensioner-pulley'],
      ['Commande à courroie > Jeu/ courroies trapézoïdales à nervures > Amortisseur de vibrations', 'crankshaft-pulley-damper'],
      ["Allumage / préchauffage > Bougie d'allumage", 'spark-plug'],
      ["Allumage / préchauffage > Bobine/unite de bobine d'allumage", 'ignition-coil-pack'],
      ["Allumage / préchauffage > Raccords de câble d'allumage", 'ignition-coil-boot'],
      ['Carburation > Mélange air-carburant > Injecteur/porte-injecteur/UPI', 'injector'],
      ['Carburation > Mélange air-carburant > Capteur/sonde lambda', 'oxygen-sensor'],
      ['Échappement > Capteur lambda', 'oxygen-sensor'],
      ['Système électrique > Démarreur > Démarreur', 'starter'],
      ['Système électrique > Alternateur / composants > Alternateur', 'alternator'],
      ['Système électrique > Batterie', 'battery'],
      ['Dispositif de freinage > Frein à disque > Plaquette de frein', 'front-brake-pad'],
      ['Dispositif de freinage > Maître-cylindre de frein', 'brake-master-booster'],
      ['Dispositif de freinage > Servofrein', 'brake-master-booster'],
      ["Embrayage / composants > Disque d'embrayage", 'clutch-disc'],
      ["Embrayage / composants > Mécanisme d'embrayage", 'clutch-pressure-plate'],
      ['Échappement > Silencieux', 'muffler'],
      ["Moteur > Lubrification > Carter d'huile et pièces de montage > Carter d'huile", 'oil-pan'],
      ['Moteur > Lubrification > Pompe à huile et pièces de montage > Pompe à huile', 'oil-pump'],
      ["Moteur > Alimentation en air > Collecteur d'admission/Tuyau", 'intake-manifold-upper'],
      ['Moteur > Carter de vilebrequin > Ventilation', 'pcv-valve'],
      ['Moteur > Système bielle-manivelle > Piston > Piston', 'piston'],
      ['Moteur > Système bielle-manivelle > Piston > Jeu de segments de piston', 'compression-ring'],
      ['Moteur > Cylindre et piston', 'piston'],
      ['Moteur > Alimentation en air > Filtre à air / carter', 'air-filter'],
      ['Alimentation carburant > Boîtier/filtre à carburant', 'fuel-filter'],
      ["Suspension d'essieu/Guidage des roues/Roues > Rotules > Rotule de suspension", 'outer-tie-rod-end'],
      ['Direction > Barre de connexion / composants > Composants de barre de connexion', 'outer-tie-rod-end'],
      ['Direction > Colonne de direction / arbre', 'steering-column'],
      ['Direction > Suspension de la direction', 'steering-rack-bracket'],
      ['Entraînement des roues > Arbre de transmission', 'axles'],
      ['Entraînement des roues > Tripode', 'axles'],
      ['Entraînement des roues > Soufflet', 'axles'],
      ['Entraînement des roues > Rotules/ jeu', 'axles'],
      ['Entraînement des essieux > Arbre de transmission > Arbre de transmission', 'drive-shaft'],
      ["Suspension d'essieu/Guidage des roues/Roues > Moyeu de roue / fixation > Moyeu de roue", 'front-hub'],
      ["Suspension d'essieu/Guidage des roues/Roues > Moyeu de roue / fixation > Roulement de roue", 'rear-wheel-bearing'],
      ["Suspension d'essieu/Guidage des roues/Roues > Moyeu de roue / fixation > Bout d'essieu", 'axle-nut'],
      ["Suspension d'essieu/Guidage des roues/Roues > Roue / fixation de la roue", 'wheel-stud'],
      ['Moteur > Support du moteur > Support moteur', 'engine-mount'],
      ['Moteur > Support du moteur > Amortisseurs de support moteur', 'engine-mount'],
      ['Moteur > Distribution moteur > Arbre à cames', 'intake-cam'],
      ['Moteur > Distribution moteur > Soupape / réglage de soupape > Soupapes / accessoires', 'intake-valve'],
      ['Moteur > Distribution moteur > Soupape / réglage de soupape > Réglage de soupape', 'hla'],
      ['Moteur > Distribution moteur > Chaîne de distribution/tension/guidage > Culbuteur/levier oscillant/pièce de press°', 'hla'],
      ['Moteur > Culasse / pieces de montage > Guide de soupape / joint / réglage', 'valve-spring'],
      ['Moteur > Alimentation en air > Papillon des gaz /-capteur > Corps papillon', 'itb-tb'],
      ['Carburation > Mélange air-carburant > Papillon des gaz / tubulure / tuyau de dépression', 'itb-tb'],
      ['Échappement > Turbocompresseur', 'efr-turbo'],
      ['Moteur > Alimentation en air > Suralimentation > Compresseur/composants', 'efr-turbo'],
      ['Échappement > Collecteur', 'stock-manifold'],
      ['Échappement > Tuyaux', 'oem-mid-pipe'],
      ['Alimentation carburant > Réservoir de carburant / accessoires', 'fuel-tank'],
      ["Alimentation carburant > Unité d'alimentation complète", 'fuel-sender'],
      ["Alimentation carburant > Pompe / accessoires > Pompe d'alimentation", 'fuel-sender'],
      ['Embrayage / composants > Butée d’embrayage/butée hydraulique > Butée de débrayage', 'throwout-bearing'],
      ['Commande à courroie > Accessoires', 'alternator-tensioner'],
      ['Système électrique > Démarreur > Composants du démarreur', 'starter-gear'],
      ['Dispositif de freinage > Frein à disque > Accessoires / composants', 'brake-hardware-1'],
      ['Entraînement hybride/électrique > Refroidissement > Tuyauterie et brides', 'cooling-hardpipe'],
      ["Suspension d'essieu/Guidage des roues/Roues > Fusée d'essieu / kit de réparation > Fusée d'essieu", 'front-upright'],
      ['Moteur > Culasse / pieces de montage > Cache-soupape', 'valve-cover'],
      ['Moteur > Joints > Cache-soupape', 'valve-cover-gasket'],
      ['Entraînement des essieux > Différentiel', 'differential'],
      ['Embrayage / composants > Palier guide / Rondelle de butée', 'pilot-bearing'],
    ];
    for (const [path, mesh] of expected) expect([path, meshOf(real(path))]).toEqual([path, mesh]);
  });

  it('maps the usual French TecDoc names, specific before generic, either side of the car', () => {
    const expected: [string, MeshId][] = [
      ['Direction > Rotule de direction', 'outer-tie-rod-end'],
      ['Suspension > Bras de suspension / Triangle avant gauche', 'front-lower-control-arm'],
      ['Suspension > Bras de suspension supérieur droit', 'front-upper-control-arm'],
      ['Suspension > Silent-bloc de bras de suspension', 'lower-control-arm-bushing'],
      ['Suspension > Silent-bloc de bras supérieur', 'upper-control-arm-bushing'],
      ["Suspension > Butée d'amortisseur", 'front-shock-bushing'],
      ["Suspension > Silent-bloc d'amortisseur", 'front-shock-bushing'],
      ["Suspension > Coupelle d'amortisseur", 'strut-top-mount'],
      ['Suspension > Amortisseur arrière', 'rear-shock-absorber'],
      ['Suspension > Ressort de suspension', 'coil-spring'],
      ['Suspension > Biellette de barre stabilisatrice avant', 'sway-bar-end-link'],
      ['Suspension > Barre stabilisatrice', 'front-sway-bar'],
      ['Moteur > Galet tendeur', 'tensioner-pulley'],
      ['Moteur > Galet enrouleur', 'idler-pulley'],
      ['Moteur > Courroie de distribution', 'timing-belt'],
      ['Refroidissement > Durite inférieure de radiateur', 'lower-radiator-hose'],
      ['Refroidissement > Bouchon de radiateur', 'radiator-cap'],
      ['Refroidissement > Roue de pompe à eau', 'water-pump-impeller'],
      ["Allumage > Faisceau d'allumage", 'ignition-coil-boot'],
      ["Injection > Rampe d'injection", 'fuel-rail'],
      ['Capteurs > Capteur de température', 'coolant-temperature-sensor'],
      ["Moteur > Collecteur d'admission inférieur", 'intake-manifold-lower'],
      ['Moteur > Soupape PCV', 'pcv-valve'],
      ['Moteur > Piston > Segment racleur', 'oil-control-ring'],
      ["Moteur > Piston > Segment d'étanchéité", 'scraper-ring'],
      ['Moteur > Piston > Axe de piston', 'wrist-pin'],
      ['Moteur > Bielle', 'connecting-rod'],
      ['Moteur > Système bielle-manivelle > Vilebrequin', 'crankshaft'],
      ['Moteur > Arbre à cames d’échappement', 'exhaust-cam'],
      ['Moteur > Soupapes d’échappement / accessoires', 'exhaust-valve'],
      ['Suspension > Moyeu de roue arrière', 'rear-hub'],
      ['Freinage > Accessoires / composants arrière', 'rear-pad-hardware'],
    ];
    for (const [path, mesh] of expected) expect([path, meshOf(cat(path))]).toEqual([path, mesh]);
  });

  it('keeps look-alike categories off the wrong mesh', () => {
    for (const path of [
      'Outils spéciaux > Outils spéciaux suspension d’essieu/guidage des roues/roues > Rotules',
      'Moteur > Système bielle-manivelle > Vilebrequin > Poulies de vilebrequin',
      'Moteur > Système bielle-manivelle > Vilebrequin > Coussinet de vilebrequin',
      "Suspension d'essieu/Guidage des roues/Roues > Fusée d'essieu / kit de réparation > Kit de réparation",
      'Alimentation carburant > Soupapes',
      'Moteur > Joints > Joint de tige de soupape',
      'Moteur > Épuration des gaz d\'échappement > Recyclage des gaz d\'échappement > Soupape, recyclage des gaz d\'échappement',
      'Entraînement des essieux > Arbre de transmission > Paliers/suspension',
      'Moteur > Système bielle-manivelle > Bielle > Coussinet de bielle',
      'Échappement > Pièces de montage > Pièces détachées de montage > Amortisseur-caoutchouc',
      'Échappement > Pièces de montage > Pièces détachées de montage > Ressort',
      'Nettoyage des vitres > Pompe-à-eau / essuie-glaces',
      'Nettoyage des vitres > Bras d\'essuie-glace / fixation',
      'Moteur > Joints > Joint de collecteur d\'admission',
      "Refroidissement > Radiateur d'eau/d'huile > Pièces de fixation du radiateur",
      'Carrosserie > Avant du véhicule > Composants/suppléments > Recouvrement/radiateur',
      'Moteur > Distribution moteur > Chaîne de distribution/tension/guidage > Tendeur de chaînes',
      'Système électrique > Alternateur / composants > Régulateur',
      'Commande à courroie > Poulie roue libre, alternateur',
      "Boîte de vitesses > Boîte de vitesse automatique > Carter d'huile et pièces de montage > Carter d'huile",
      'Carburation > Carburateurs > Commande / Amortisseurs / Boîtiers',
    ]) {
      expect([path, partFamily(real(path))]).toEqual([path, { kind: 'generic' }]);
    }
  });

  it('captions a family-generic shape as an approximation, a like-for-like part not', () => {
    expect(partFamily(real('Direction > Rotules'))).toEqual({ kind: 'mesh', mesh: 'outer-tie-rod-end', approximate: false });
    expect(partFamily(real("Suspension d'essieu/Guidage des roues/Roues > Rotules > Rotule de suspension"))).toEqual({ kind: 'mesh', mesh: 'outer-tie-rod-end', approximate: true });
    expect(partFamily(real("Allumage / préchauffage > Bougie d'allumage"))).toEqual({ kind: 'mesh', mesh: 'spark-plug', approximate: false });
    expect(partFamily(real("Suspension d'essieu/Guidage des roues/Roues > Bras oscillant / suspension > Bras (transversal, longitudinal, oblique)"))).toMatchObject({ approximate: true });
  });

  it('never draws the outer tie rod end for an inner axial joint', () => {
    expect(partFamily(cat('Direction > Rotule axiale'))).toEqual({ kind: 'generic' });
    expect(partFamily(cat('Direction > Rotule de direction intérieure'))).toEqual({ kind: 'generic' });
  });

  it('reads the TecDoc product group before the category node it sits in', () => {
    const rotules = real('Direction > Rotules');
    expect(partFamily(rotules, [], 'Rotule de barre de connexion')).toEqual({ kind: 'mesh', mesh: 'outer-tie-rod-end', approximate: false });
    expect(partFamily(rotules, [], 'Rotule de direction intérieure, barre de connexion')).toEqual({ kind: 'generic' });
    expect(partFamily(rotules, [], 'Rotule de direction')).toEqual({ kind: 'generic' });
    expect(partFamily(real("Allumage / préchauffage > Bougie d'allumage"), [], "Bougie d'allumage")).toEqual({ kind: 'mesh', mesh: 'spark-plug', approximate: false });
  });

  it('gives every family without a mesh the generic placeholder', () => {
    expect(partFamily(cat('Accessoires > Chaîne à neige'))).toEqual({ kind: 'generic' });
    expect(partFamily(cat('Dispositif de freinage > Liquide de frein'))).toEqual({ kind: 'generic' });
  });
});

describe('mesh table', () => {
  it('maps a Mazda tree leaf to a mesh, never to a missing file', () => {
    const shipped = new Set(Object.keys(import.meta.glob('../public/parts/*.glb', { query: '?url' })).map((k) => k.split('/').at(-1)));
    const mapped = tree.map((c) => partFamily({ path: c.path, name: c.name })).filter((f) => f.kind === 'mesh');
    expect(mapped.filter((f) => !shipped.has(meshFile(f.mesh)))).toEqual([]);
  });

  it('points every rule at a mesh that ships in public/parts', () => {
    const shipped = new Set(Object.keys(import.meta.glob('../public/parts/*.glb', { query: '?url' })).map((k) => k.split('/').at(-1)));
    expect(MESHES.filter((id) => !shipped.has(meshFile(id)))).toEqual([]);
  });

  it('reaches every shipped mesh from at least one rule', () => {
    const targets = new Set(MESH_RULES.flatMap((r) => [r.mesh, ...(r.variants ?? []).map((v) => v.mesh)]));
    expect(MESHES.filter((m) => !targets.has(m))).toEqual([]);
  });
});
