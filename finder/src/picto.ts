import { foldText } from './search';

export const PICTO_IDS = [
  'body', 'body-front', 'body-rear', 'body-panel', 'door', 'mirror', 'grille', 'seat', 'lamp',
  'rotating-machine', 'gauge', 'relay', 'electrical',
  'timing', 'belt', 'gasket', 'oil', 'air-intake', 'piston', 'cylinder-head', 'egr', 'engine-mount', 'engine',
  'fuel-injection', 'gearbox', 'exhaust', 'brake', 'steering', 'axle', 'shock', 'driveshaft', 'radiator',
  'clutch', 'spark', 'fuel', 'ac', 'fan', 'wheel', 'wiper', 'safety', 'screen', 'comfort', 'lock',
  'accessory', 'roof-rack', 'tow', 'ev', 'pneumatic', 'service', 'filter', 'tool',
] as const;

export type PictoId = (typeof PICTO_IDS)[number];

export interface CategoryPicto {
  id: PictoId;
  label: string;
}

// First match wins on the accent- and case-folded category path: sub-branch rules sit above their branch.
const RULES: readonly (readonly [RegExp, PictoId, string])[] = [
  [/^carrosserie > arriere de la voiture/, 'body-rear', 'Arrière du véhicule'],
  [/^carrosserie > avant du vehicule/, 'body-front', 'Avant du véhicule'],
  [/^carrosserie > (feux|projecteur|phares)/, 'lamp', 'Éclairage'],
  [/^carrosserie > pieces de carrosserie/, 'body-panel', 'Pièces de carrosserie'],
  [/^carrosserie > habitacle/, 'seat', 'Habitacle'],
  [/^carrosserie > vitrages\/miroirs/, 'mirror', 'Vitrages et rétroviseurs'],
  [/^carrosserie > trappes\/capots\/portes/, 'door', 'Ouvrants'],
  [/^carrosserie > grilles/, 'grille', 'Grilles et logos'],
  [/^carrosserie/, 'body', 'Carrosserie'],

  [/^systeme electrique > (feux|projecteur|phares supplementaires)/, 'lamp', 'Éclairage'],
  [/^systeme electrique > (demarreur|alternateur)/, 'rotating-machine', 'Démarrage et charge'],
  [/^systeme electrique > instruments/, 'gauge', 'Instruments'],
  [/^systeme electrique > (relais|plombs|interrupteur|electricite centrale|boite a fusibles|centrale clignotante|minuterie)/, 'relay', 'Relais et fusibles'],
  [/^systeme electrique/, 'electrical', 'Système électrique'],

  [/^moteur > distribution moteur/, 'timing', 'Distribution'],
  [/^moteur > commande a courroie/, 'belt', 'Courroies'],
  [/^moteur > joints/, 'gasket', 'Joints'],
  [/^moteur > culasse.*> [^>]*joint[^>]*$/, 'gasket', 'Joints'],
  [/^moteur > lubrification/, 'oil', 'Lubrification'],
  [/^moteur > alimentation en air/, 'air-intake', "Admission d'air"],
  [/^moteur > systeme bielle-manivelle/, 'piston', 'Embiellage'],
  [/^moteur > culasse/, 'cylinder-head', 'Culasse'],
  [/^moteur > epuration des gaz/, 'egr', 'Recyclage des gaz'],
  [/^moteur > support du moteur/, 'engine-mount', 'Support moteur'],
  [/^moteur/, 'engine', 'Moteur'],

  [/^commande a courroie/, 'belt', 'Courroies'],
  [/^carburation/, 'fuel-injection', 'Injection / carburation'],
  [/^boite de vitesses/, 'gearbox', 'Boîte de vitesses'],
  [/^echappement/, 'exhaust', 'Échappement'],
  [/^dispositif de freinage/, 'brake', 'Freinage'],
  [/^direction/, 'steering', 'Direction'],
  [/^suspension d'essieu/, 'axle', 'Train roulant'],
  [/^suspension\/amortissement/, 'shock', 'Suspension'],
  [/^entrainement des (roues|essieux)/, 'driveshaft', 'Transmission'],
  [/^refroidissement/, 'radiator', 'Refroidissement'],
  [/^embrayage/, 'clutch', 'Embrayage'],
  [/^allumage/, 'spark', 'Allumage'],
  [/^alimentation carburant/, 'fuel', 'Alimentation carburant'],
  [/^climatisation/, 'ac', 'Climatisation'],
  [/^chauffage/, 'fan', 'Chauffage / ventilation'],
  [/^roues\/pneus/, 'wheel', 'Roues et pneus'],
  [/^nettoyage des (vitres|phares)/, 'wiper', 'Nettoyage'],
  [/^systemes de securite/, 'safety', 'Sécurité'],
  [/^systeme d'information/, 'screen', 'Information et communication'],
  [/^systemes de confort/, 'comfort', 'Confort'],
  [/^verrouillage/, 'lock', 'Verrouillage'],
  [/^equipement interieur/, 'seat', 'Équipement intérieur'],
  [/^accessoires/, 'accessory', 'Accessoires'],
  [/^portes-bagages/, 'roof-rack', 'Porte-bagages'],
  [/^dispositif d'attelage/, 'tow', 'Attelage'],
  [/^entrainement hybride/, 'ev', 'Hybride / électrique'],
  [/^systeme pneumatique/, 'pneumatic', 'Système pneumatique'],
  [/^pieces de maintenance/, 'service', 'Entretien'],
  [/^filtre/, 'filter', 'Filtres'],
  [/^outils/, 'tool', 'Outils'],
];

export function categoryPicto(path: string): CategoryPicto | null {
  const folded = foldText(path);
  const rule = RULES.find(([re]) => re.test(folded));
  return rule ? { id: rule[1], label: rule[2] } : null;
}
