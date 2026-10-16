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
    expect(partFamily(cat('Filtre > Filtre à air pour habitacle'), round)).toEqual({ kind: 'photo' });
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
      ['Moteur > Alimentation en air > Papillon des gaz /-capteur > Corps papillon', 'throttle-body'],
      ['Carburation > Mélange air-carburant > Papillon des gaz / tubulure / tuyau de dépression', 'throttle-body'],
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

  const approx = (mesh: MeshId) => ({ kind: 'mesh', mesh, approximate: true });
  const expectMeshes = (rows: [{ path: string; name: string }, MeshId][]) => {
    for (const [category, mesh] of rows) expect([category.path, partFamily(category)]).toEqual([category.path, approx(mesh)]);
  };

  it('draws the bulb matching the lamp it sits in', () => {
    expectMeshes([
      [real('Carrosserie > Projecteurs principaux / composants > Ampoule pour projecteur principal'), 'bulb-h7'],
      [real('Carrosserie > Avant du véhicule > Projecteur longue portée / composants > Ampoule /projecteur longue portée'), 'bulb-h7'],
      [real('Carrosserie > Avant du véhicule > Projecteur antibrouillard/ composants > Ampoule pour projecteur antibrouillard'), 'bulb-h7'],
      [real('Carrosserie > Phares supplémentaires/composants > Projecteur de travail, composants > Ampoule, projecteurs de travail'), 'bulb-h7'],
      [cat('Système électrique > Assortiments d\'ampoules'), 'bulb-h7'],
      [real('Carrosserie > Arrière de la voiture > Feu clignotant/composants > Ampoule de feu clignotant'), 'bulb-p21w'],
      [real('Carrosserie > Arrière de la voiture > Feu-stop / composants > Ampoules pour feux de stop'), 'bulb-p21w'],
      [real('Carrosserie > Feux > Feu arrière/Composants > Ampoule de feu arrière'), 'bulb-p21w'],
      [real('Système électrique > Feux > Feu arrière / composants > Ampoule pour feux arrières'), 'bulb-p21w'],
      [real('Système électrique > Feux > Feu de recul / composants > Ampoule de feu de recul'), 'bulb-p21w'],
      [real('Système électrique > Feux > Feu de brouillard arrière/Composants > Ampoules de feu de brouillard arrière'), 'bulb-p21w'],
      [real("Carrosserie > Avant du véhicule > Feu de position / d'encombrement / composants > Ampoule"), 'bulb-w5w'],
      [real('Système électrique > Feux > Feu éclaireur de plaque/composants > Ampoule du feu éclaireur de plaque'), 'bulb-w5w'],
      [cat('Système électrique > Phares supplémentaires/composants > Éclairage de virage/composants > Ampoule de virage'), 'bulb-h7'],
    ]);
  });

  it('keeps a lamp unit off the bulb, and the fog lamp unit on its own mesh', () => {
    expect(partFamily(real('Carrosserie > Projecteurs principaux / composants > Projecteurs principaux / à insérer'))).toEqual({ kind: 'photo' });
    expect(partFamily(real("Carrosserie > Avant du véhicule > Feu de position / d'encombrement / composants > Feu de stationnement/de position"))).toEqual({ kind: 'photo' });
    expectMeshes([[real('Carrosserie > Avant du véhicule > Projecteur antibrouillard/ composants > Projecteur antibrouillard / Optique'), 'fog-lamp']]);
  });

  it('draws a jerrycan for the fluids', () => {
    expectMeshes([
      [real('Direction > Huiles'), 'jerrycan-5l'],
      [real('Moteur > Lubrification > Huiles'), 'jerrycan-5l'],
      [real('Refroidissement > Antigel'), 'jerrycan-5l'],
      [real('Dispositif de freinage > Liquide de frein'), 'jerrycan-5l'],
      [real('Embrayage / composants > Fluide hydraulique'), 'jerrycan-5l'],
    ]);
  });

  it('draws the drum brake parts', () => {
    expectMeshes([
      [cat('Dispositif de freinage > Frein à tambour > Tambour de frein'), 'brake-drum'],
      [cat('Dispositif de freinage > Frein à tambour > Garniture/machoire de frein'), 'brake-shoe'],
      [cat('Dispositif de freinage > Cylindre de roue'), 'wheel-cylinder'],
      [cat('Dispositif de freinage > Frein à tambour > Cylindre de roue'), 'wheel-cylinder'],
    ]);
  });

  it('draws the brake lines and hoses', () => {
    expectMeshes([
      [real('Dispositif de freinage > Conduites de frein'), 'brake-line'],
      [real('Dispositif de freinage > Flexibles de frein'), 'brake-hose'],
    ]);
  });

  it('draws a fluid line for the oil, steering, A/C and clutch pipes, not the exhaust or air pipes', () => {
    expectMeshes([
      [cat("Moteur > Lubrification > Tuyaux d'huile / Conduites"), 'fluid-line'],
      [cat("Refroidissement > Tuyauterie et brides > Tuyaux d'huile / Conduites"), 'fluid-line'],
      [real('Direction > Tuyau de direction / conduite'), 'fluid-line'],
      [real('Climatisation > Tuyaux/Conduites'), 'fluid-line'],
      [cat('Embrayage / composants > Commande d’embrayage > Tuyaux / conduites'), 'fluid-line'],
    ]);
    expect(meshOf(real('Échappement > Tuyaux'))).toBe('oem-mid-pipe');
    expect(partFamily(cat('Moteur > Alimentation en air > Flexibles/Conduites'))).toEqual({ kind: 'photo' });
    expect(partFamily(cat('Chauffage / Ventilation > Gaines/tuyaux'))).toEqual({ kind: 'photo' });
    expect(meshOf(cat('Entraînement hybride/électrique > Refroidissement > Tuyauterie et brides'))).toBe('cooling-hardpipe');
  });

  it('draws a control cable for the clutch and the parking brake', () => {
    expectMeshes([
      [cat("Embrayage / composants > Commande d'embrayage > Câble d'embrayage"), 'control-cable'],
      [real('Dispositif de freinage > Levier / câble / tirette'), 'control-cable'],
      [real('Dispositif de freinage > Freins de stationnement'), 'control-cable'],
      [real('Dispositif de freinage > Frein à tambour > Freins de stationnement'), 'control-cable'],
    ]);
  });

  it('draws the belt pulleys', () => {
    expectMeshes([
      [real('Commande à courroie > Poulie roue libre, alternateur'), 'freewheel-pulley'],
      [real('Moteur > Commande à courroie > Poulie'), 'ribbed-pulley'],
      [real('Moteur > Distribution moteur > Poulie'), 'ribbed-pulley'],
      [cat('Refroidissement > Pompe à eau / joint > Poulie'), 'ribbed-pulley'],
    ]);
    expect(partFamily(real('Moteur > Système bielle-manivelle > Vilebrequin > Poulies de vilebrequin'))).toEqual({ kind: 'photo' });
    expect(partFamily(cat('Moteur > Commande à courroie > Poulie'), [], 'Poulie, pompe de direction assistée')).toEqual({ kind: 'photo' });
  });

  it('draws the flywheel and the clutch hydraulics', () => {
    expectMeshes([
      [cat('Embrayage / composants > Volant-moteur'), 'flywheel'],
      [cat('Moteur > Système bielle-manivelle > Volant-moteur'), 'flywheel'],
      [real("Embrayage / composants > Commande d'embrayage > Récepteur d'embrayage"), 'clutch-slave-cylinder'],
      [real("Embrayage / composants > Commande d'embrayage > Cylindre émetteur"), 'clutch-master-cylinder'],
    ]);
    expect(partFamily(real('Direction > Volant /- composants'))).toEqual({ kind: 'photo' });
    expect(meshOf(real('Dispositif de freinage > Maître-cylindre de frein'))).toBe('brake-master-booster');
  });

  it('draws the vacuum pump, the catalytic converter and the expansion tanks', () => {
    expectMeshes([
      [cat('Dispositif de freinage > Pompe à vide'), 'vacuum-pump'],
      [real('Moteur > Culasse / pieces de montage > Pompe à vide'), 'vacuum-pump'],
      [real('Échappement > Catalyseur'), 'catalytic-converter'],
      [real("Refroidissement > Radiateur d'eau/d'huile > Vase d'expansion"), 'expansion-tank'],
      [real("Direction > Vase d'expansion de l'huile hydraulique"), 'expansion-tank'],
    ]);
    expect(meshOf(cat("Refroidissement > Bouchon de vase d'expansion"))).toBe('radiator-cap');
  });

  it('draws the wiper blade and motor, the horn, and leaves the other window cleaning parts on their photo', () => {
    expectMeshes([
      [real("Nettoyage des vitres > Balai d'essuie-glace / raclette"), 'wiper-blade'],
      [real("Nettoyage des vitres > Moteur d'essuie-glace"), 'wiper-motor'],
      [real('Système électrique > Avertisseur sonore / trompette'), 'horn'],
    ]);
    for (const path of ['Nettoyage des vitres > Gicleur d\'eau de lavage', 'Nettoyage des vitres > Tringlerie d\'essuie-glace / commande', "Nettoyage des vitres > Réservoir d'eau de lavage / conduite"]) {
      expect([path, partFamily(real(path))]).toEqual([path, { kind: 'photo' }]);
    }
  });

  it('draws the speed sensor, the EGR valve and the A/C compressor', () => {
    expectMeshes([
      [real("Allumage / préchauffage > Générateur d'impulsions"), 'speed-sensor'],
      [real("Moteur > Épuration des gaz d'échappement > Recyclage des gaz d'échappement > Module-EGR"), 'egr-valve'],
      [real("Moteur > Épuration des gaz d'échappement > Recyclage des gaz d'échappement > Soupape, recyclage des gaz d'échappement"), 'egr-valve'],
      [real("Carburation > Épuration des gaz d'échappement > Recyclage des gaz d'échappement > Vanne EGR/Tube d'aspiration"), 'egr-valve'],
      [real('Climatisation > Compresseur / composants'), 'ac-compressor'],
    ]);
    expect(meshOf(real('Moteur > Alimentation en air > Suralimentation > Compresseur/composants'))).toBe('efr-turbo');
    expect(partFamily(real("Moteur > Épuration des gaz d'échappement > Recyclage des gaz d'échappement > Joints"))).toEqual({ kind: 'photo' });
  });

  it('draws the gearbox body, not its parts', () => {
    expectMeshes([
      [real('Boîte de vitesses > Boîte de vitesses manuelle > Boîte de vitesses'), 'gearbox'],
      [real('Boîte de vitesses > Boîte de vitesse automatique > Boîtier/composants'), 'gearbox'],
      [cat('Boîte de vitesses > Boîte de vitesses manuelle > Carter/composants'), 'gearbox'],
    ]);
    for (const path of ['Boîte de vitesses > Boîte de vitesses manuelle > Joints', 'Boîte de vitesses > Boîte de vitesse automatique > Capteur', 'Boîte de vitesses > Boîte de vitesse automatique > Contrôle / hydraulique', 'Boîte de vitesses > Boîte de vitesses manuelle > Commande de boîte de vitesses']) {
      expect([path, partFamily(cat(path))]).toEqual([path, { kind: 'photo' }]);
    }
  });

  it('draws the timing chain kit and guide, not the tensioner', () => {
    expectMeshes([
      [real('Moteur > Distribution moteur > Chaîne de distribution/tension/guidage > Kit de distribution par chaîne'), 'timing-chain-kit'],
      [real('Moteur > Distribution moteur > Chaîne de distribution/tension/guidage > Chaîne de distribution'), 'timing-chain-kit'],
      [real('Moteur > Distribution moteur > Chaîne de distribution/tension/guidage > Coulisse'), 'timing-chain-kit'],
    ]);
    expect(partFamily(real('Moteur > Distribution moteur > Chaîne de distribution/tension/guidage > Tendeur de chaînes'))).toEqual({ kind: 'photo' });
  });

  it('draws the power steering pump and the intercooler', () => {
    expectMeshes([
      [cat("Moteur > Alimentation en air > Suralimentation > Refroidisseur d'air de suralimentation"), 'intercooler'],
    ]);
  });

  it('keeps look-alike categories off the wrong mesh', () => {
    for (const path of [
      'Outils spéciaux > Outils spéciaux suspension d’essieu/guidage des roues/roues > Rotules',
      'Moteur > Système bielle-manivelle > Vilebrequin > Poulies de vilebrequin',
      'Moteur > Système bielle-manivelle > Vilebrequin > Coussinet de vilebrequin',
      "Suspension d'essieu/Guidage des roues/Roues > Fusée d'essieu / kit de réparation > Kit de réparation",
      'Alimentation carburant > Soupapes',
      'Moteur > Joints > Joint de tige de soupape',
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
      "Boîte de vitesses > Boîte de vitesse automatique > Carter d'huile et pièces de montage > Carter d'huile",
      'Carburation > Carburateurs > Commande / Amortisseurs / Boîtiers',
    ]) {
      expect([path, partFamily(real(path))]).toEqual([path, { kind: 'photo' }]);
    }
  });

  it('captions a family-generic shape as an approximation, a like-for-like part not', () => {
    expect(partFamily(real('Direction > Rotules'))).toEqual({ kind: 'mesh', mesh: 'outer-tie-rod-end', approximate: false });
    expect(partFamily(real("Suspension d'essieu/Guidage des roues/Roues > Rotules > Rotule de suspension"))).toEqual({ kind: 'mesh', mesh: 'outer-tie-rod-end', approximate: true });
    expect(partFamily(real("Allumage / préchauffage > Bougie d'allumage"))).toEqual({ kind: 'mesh', mesh: 'spark-plug', approximate: false });
    expect(partFamily(real("Suspension d'essieu/Guidage des roues/Roues > Bras oscillant / suspension > Bras (transversal, longitudinal, oblique)"))).toMatchObject({ approximate: true });
  });

  it('never draws the outer tie rod end for an inner axial joint', () => {
    expect(partFamily(cat('Direction > Rotule axiale'))).toEqual({ kind: 'photo' });
    expect(partFamily(cat('Direction > Rotule de direction intérieure'))).toEqual({ kind: 'photo' });
  });

  it('reads the TecDoc product group before the category node it sits in', () => {
    const rotules = real('Direction > Rotules');
    expect(partFamily(rotules, [], 'Rotule de barre de connexion')).toEqual({ kind: 'mesh', mesh: 'outer-tie-rod-end', approximate: false });
    expect(partFamily(rotules, [], 'Rotule de direction intérieure, barre de connexion')).toEqual({ kind: 'photo' });
    expect(partFamily(rotules, [], 'Rotule de direction')).toEqual({ kind: 'photo' });
    expect(partFamily(real("Allumage / préchauffage > Bougie d'allumage"), [], "Bougie d'allumage")).toEqual({ kind: 'mesh', mesh: 'spark-plug', approximate: false });
  });

  it('shows the photo for a product group that is not the part its leaf is drawn as', () => {
    for (const [path, group] of [
      ['Filtre > Filtre à huile', "Joint, filtre à huile"],
      ['Filtre > Filtre à huile', 'Module de filtre à huile'],
      ['Moteur > Alimentation en air > Filtre à air / carter', "Tuyau d'aspiration, alimentation d'air"],
      ['Moteur > Alimentation en air > Filtre à air / carter', 'Boîte, filtre à air'],
      ['Refroidissement > Interrupteur / capteur', "Capteur, température d'huile"],
      ['Refroidissement > Interrupteur / capteur', 'Kit rép. de câble, capteur de temp. liquide de refroidis.'],
      ['Moteur > Support du moteur > Support moteur', 'Support de palier, support du moteur'],
      ['Moteur > Support du moteur > Support moteur', 'Butée élastique, support du moteur'],
    ] as const) {
      expect([group, partFamily(cat(path), [], group)]).toEqual([group, { kind: 'photo' }]);
    }
  });

  it('keeps the leaf mesh for the product groups that are the part', () => {
    expect(partFamily(cat('Filtre > Filtre à huile'), [], 'Filtre à huile')).toEqual({ kind: 'filter', filter: 'oil' });
    expect(partFamily(cat('Refroidissement > Interrupteur / capteur'), [], 'Sonde, température du liquide de refroidissement')).toMatchObject({ kind: 'mesh', mesh: 'coolant-temperature-sensor' });
    expect(partFamily(cat('Refroidissement > Joint/Thermostat > Thermostat'), [], 'Thermostat, liquide de refroidissement')).toMatchObject({ kind: 'mesh', mesh: 'thermostat' });
    expect(partFamily(cat('Moteur > Support du moteur > Support moteur'), [], 'Support moteur')).toMatchObject({ kind: 'mesh', mesh: 'engine-mount' });
  });

  it('draws the crankshaft pulley for its product group inside the mixed pulley leaf', () => {
    const pulley = cat('Moteur > Commande à courroie > Poulie');
    expect(partFamily(pulley, [], 'Poulie, vilebrequin')).toEqual({ kind: 'mesh', mesh: 'crankshaft-pulley-damper', approximate: true });
    expect(partFamily(pulley, [], 'Poulie, pompe de direction assistée')).toEqual({ kind: 'photo' });
    expect(partFamily(pulley, [], 'Jeu de courroies trapézoïdales à nervures')).toEqual({ kind: 'photo' });
  });

  it('draws the thermostat housing for its product group, the thermostat for the leaf', () => {
    const thermostat = real('Refroidissement > Joint/Thermostat > Thermostat');
    expect(partFamily(thermostat, [], 'Boîtier du thermostat')).toEqual({ kind: 'mesh', mesh: 'thermostat-housing', approximate: true });
    expect(partFamily(thermostat, [], 'Thermostat, liquide de refroidissement')).toMatchObject({ mesh: 'thermostat' });
  });

  it('never draws a disc brake for a drum kit, nor for a kit that may be drums', () => {
    expect(partFamily(cat('Dispositif de freinage > Frein à tambour > Kit de freins'))).toEqual({ kind: 'photo' });
    expect(partFamily(cat('Dispositif de freinage > Frein de haute performance > Kit de freins'))).toEqual({ kind: 'photo' });
    expect(partFamily(cat('Dispositif de freinage > Frein à disque > Kit de freins'))).toMatchObject({ kind: 'brake' });
  });

  it('gives every family without a mesh the catalogue photo instead of a drawing', () => {
    expect(partFamily(cat('Accessoires > Chaîne à neige'))).toEqual({ kind: 'photo' });
    expect(partFamily(cat("Dispositif de freinage > Témoin d'usure"))).toEqual({ kind: 'photo' });
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
