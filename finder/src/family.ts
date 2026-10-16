import type { MeshId } from './meshes';
import { foldText } from './search';
import type { Dimension, Family } from './types';

const BRAKE = /(disque de freins?|etrier|kit de freins)/;
const AXLE_KEY = /^(position|cote_d_assemblage)/;

function axleFrom(path: string, dimensions: readonly Dimension[]): 'front' | 'rear' {
  if (/arriere/.test(path)) return 'rear';
  if (/avant/.test(path)) return 'front';
  const placed = dimensions.find((d) => AXLE_KEY.test(d.key) && /arriere|avant/.test(foldText(d.value)));
  return placed && /arriere/.test(foldText(placed.value)) ? 'rear' : 'front';
}

const isCylindrical = (dimensions: readonly Dimension[]): boolean =>
  dimensions.some((d) => /^diametre(_exterieur|_1)?$/.test(d.key) && d.number !== null);

// TecDoc category → mesh, first match wins, so specific rules sit above generic ones. `leaf` reads the
// category's last path segment, `within` and `except` the whole path, all accent- and case-folded.
// `mesh: null` pins a look-alike to the catalogue photo. `approximate` (default true) captions the
// view as the family's generic shape: these meshes come from one car, never from the user's.
export interface MeshRule {
  leaf: RegExp;
  within?: RegExp;
  except?: RegExp;
  mesh: MeshId | null;
  approximate?: boolean;
  variants?: { when: RegExp | 'rear'; mesh: MeshId }[];
}

export const MESH_RULES: readonly MeshRule[] = [
  // Specific parts that sit in branches pinned to the photo below, or under a generic leaf: above the pins.
  { leaf: /^ampoules?/, within: /brouillard arriere|feux? arrieres?|feu-stop|feux de stop|feu de recul|clignotant/, mesh: 'bulb-p21w' },
  { leaf: /^ampoules?/, within: /feu de position|encombrement|plaque/, mesh: 'bulb-w5w' },
  { leaf: /^ampoules?|^assortiments d'ampoules/, within: /projecteurs? principa|longue portee|antibrouillard|projecteur de travail|virage|assortiments/, mesh: 'bulb-h7' },
  { leaf: /^projecteur antibrouillard/, mesh: 'fog-lamp' },
  { leaf: /^(huiles|antigel|liquide de frein|fluide hydraulique)$/, mesh: 'jerrycan-5l' },
  { leaf: /^tambour de frein/, mesh: 'brake-drum' },
  { leaf: /^garniture\/machoire de frein|^machoire de frein/, mesh: 'brake-shoe' },
  { leaf: /^cylindre de roue/, mesh: 'wheel-cylinder' },
  { leaf: /^conduites? de frein/, mesh: 'brake-line' },
  { leaf: /^flexibles? de frein/, mesh: 'brake-hose' },
  { leaf: /^tuyaux? d'huile|^tuyau de direction|^tuyaux ?\/ ?conduites/, mesh: 'fluid-line' },
  { leaf: /^cable d'embrayage|^levier \/ cable \/ tirette|^freins de stationnement$/, mesh: 'control-cable' },
  { leaf: /^poulie roue libre/, mesh: 'freewheel-pulley' },
  { leaf: /^poulie$/, within: /commande a courroie|pompe a eau|distribution moteur/, mesh: 'ribbed-pulley' },
  { leaf: /^volant-moteur/, mesh: 'flywheel' },
  { leaf: /^recepteur d'embrayage/, mesh: 'clutch-slave-cylinder' },
  { leaf: /^cylindre emetteur/, within: /embrayage/, mesh: 'clutch-master-cylinder' },
  { leaf: /^pompe a vide/, mesh: 'vacuum-pump' },
  { leaf: /^catalyseur/, mesh: 'catalytic-converter' },
  { leaf: /^vase d'expansion/, mesh: 'expansion-tank' },
  { leaf: /^balai d'essuie-glace/, mesh: 'wiper-blade' },
  { leaf: /^moteur d'essuie-glace/, mesh: 'wiper-motor' },
  { leaf: /^avertisseur sonore/, mesh: 'horn' },
  { leaf: /^generateur d'impulsions/, mesh: 'speed-sensor' },
  { leaf: /^module-egr|^soupape, recyclage des gaz d'echappement|^vanne egr|^controle des gaz d'echappement de rge/, mesh: 'egr-valve' },
  { leaf: /^compresseur \/ composants/, within: /^climatisation/, mesh: 'ac-compressor' },
  { leaf: /^boite de vitesses$|^(carter|boitier)\/composants/, within: /^boite de vitesse/, mesh: 'gearbox' },
  { leaf: /^kit de distribution par chaine|^chaine de distribution|^coulisse$/, mesh: 'timing-chain-kit' },
  { leaf: /^boitier du thermostat/, mesh: 'thermostat-housing' },
  { leaf: /^refroidisseur d'air de suralimentation/, mesh: 'intercooler' },

  { leaf: /./, within: /outils|boite de vitesse|nettoyage des vitres|carburateur/, mesh: null },
  { leaf: /^composants du demarreur/, mesh: 'starter-gear' },
  { leaf: /^composants de barre de connexion/, mesh: 'outer-tie-rod-end' },
  { leaf: /^(joint|kit de reparation|pieces? de fixation|vis\b|coussinet|regulateur|contacteur|composants)/, mesh: null },
  { leaf: /^tendeur de chaine|chaine de/, mesh: null },

  { leaf: /plaquette/, mesh: 'front-brake-pad', variants: [{ when: 'rear', mesh: 'rear-brake-pad' }] },
  { leaf: /maitre-cylindre de frein|servofrein/, mesh: 'brake-master-booster' },

  { leaf: /rotules?\/ ?jeu|^tripode|^soufflet$/, within: /entrainement des roues/, mesh: 'axles' },
  { leaf: /rotule axiale|rotule de direction interieure/, mesh: null },
  { leaf: /rotule de suspension|rotule de bras/, mesh: 'outer-tie-rod-end' },
  { leaf: /barre de connexion|barre d'accouplement|biellette de direction/, mesh: 'outer-tie-rod-end' },
  { leaf: /rotule/, within: /direction/, mesh: 'outer-tie-rod-end', approximate: false },
  { leaf: /cremaillere/, mesh: 'steering-rack' },
  { leaf: /^suspension de la direction/, mesh: 'steering-rack-bracket' },
  { leaf: /^colonne de direction/, mesh: 'steering-column' },

  { leaf: /^arbre de transmission$/, within: /entrainement des roues/, mesh: 'axles' },
  { leaf: /^arbre de transmission$/, within: /entrainement des essieux/, mesh: 'drive-shaft' },
  { leaf: /^moyeu de roue/, mesh: 'front-hub', variants: [{ when: 'rear', mesh: 'rear-hub' }] },
  { leaf: /roulement de roue/, mesh: 'rear-wheel-bearing' },
  { leaf: /^bout d'essieu/, mesh: 'axle-nut' },
  { leaf: /^roue \/ fixation de la roue/, mesh: 'wheel-stud' },

  { leaf: /butee d'amortisseur|silent-?bloc d'amortisseur|palier de coupelle/, mesh: 'front-shock-bushing' },
  { leaf: /^pieces de montage$/, within: /jambe de suspension\/amortisseur/, mesh: 'front-shock-bushing' },
  { leaf: /coupelle|fixation jambe de force/, mesh: 'strut-top-mount' },
  { leaf: /biellette/, within: /stabilis/, mesh: 'sway-bar-end-link' },
  { leaf: /^barre de suspension$/, within: /stabilis/, mesh: 'sway-bar-end-link' },
  { leaf: /barre stabilisatrice|^stabilisateur/, mesh: 'front-sway-bar' },
  { leaf: /silent-?bloc|^fixation\/placement|palier de bras/, mesh: 'lower-control-arm-bushing', variants: [{ when: /superieur/, mesh: 'upper-control-arm-bushing' }] },
  { leaf: /bras de tension|bras tendeur|galet tendeur|poulie de tension|tendeurs? de courroie/, mesh: 'tensioner-pulley' },
  { leaf: /^bras|triangle/, within: /suspension|essieu/, mesh: 'front-lower-control-arm', variants: [{ when: /superieur/, mesh: 'front-upper-control-arm' }] },
  { leaf: /amortisseur de vibrations|^poulie de vilebrequin$|poulie damper/, mesh: 'crankshaft-pulley-damper' },
  { leaf: /amortisseur|^jambe de suspension/, within: /suspension|amortissement/, except: /support moteur|caoutchouc/, mesh: 'front-shock-absorber', variants: [{ when: 'rear', mesh: 'rear-shock-absorber' }] },
  { leaf: /ressort de suspension|ressort helicoidal|^ressorts?\b(?! pneumatique)/, within: /suspension/, mesh: 'coil-spring' },
  { leaf: /^suspension$/, within: /suspension\/amortissement/, mesh: 'coil-spring' },

  { leaf: /thermostat/, mesh: 'thermostat' },
  { leaf: /roue de pompe a eau|turbine de pompe a eau/, mesh: 'water-pump-impeller' },
  { leaf: /pompe a eau/, mesh: 'water-pump' },
  { leaf: /galet enrouleur|poulie de renvoi|poulie de guidage/, mesh: 'idler-pulley' },
  { leaf: /courroie (crantee|de distribution|trapezoidale)|kit de courroie/, mesh: 'timing-belt' },
  { leaf: /radiateur d'huile/, mesh: 'oil-cooler' },
  { leaf: /bouchon de radiateur|bouchon de vase d'expansion/, mesh: 'radiator-cap' },
  { leaf: /^radiateur/, except: /fixation|recouvrement/, mesh: 'radiator' },
  { leaf: /durite|tuyau de refroidissement/, mesh: 'upper-radiator-hose', variants: [{ when: /inferieure|\bbas\b/, mesh: 'lower-radiator-hose' }] },
  { leaf: /ventilateur/, mesh: 'cooling-fan' },
  { leaf: /lambda/, mesh: 'oxygen-sensor' },
  { leaf: /capteur de temperature|sonde de temperature/, mesh: 'coolant-temperature-sensor' },
  { leaf: /^interrupteur \/ capteur$/, within: /^refroidissement/, mesh: 'coolant-temperature-sensor' },

  { leaf: /bougie d'allumage/, mesh: 'spark-plug', approximate: false },
  { leaf: /faisceau d'allumage|cable d'allumage|capuchon de bougie/, mesh: 'ignition-coil-boot' },
  { leaf: /bobine/, mesh: 'ignition-coil-pack' },
  { leaf: /rampe d'injection|rampe de carburant/, mesh: 'fuel-rail' },
  { leaf: /injecteur/, mesh: 'injector' },
  { leaf: /^demarreur/, mesh: 'starter' },
  { leaf: /^alternateur/, mesh: 'alternator' },
  { leaf: /^batterie/, mesh: 'battery' },

  { leaf: /filtre a air/, mesh: 'air-filter' },
  { leaf: /filtre a carburant/, mesh: 'fuel-filter' },
  { leaf: /disque d'embrayage|kit d'embrayage/, mesh: 'clutch-disc' },
  { leaf: /mecanisme d'embrayage/, mesh: 'clutch-pressure-plate' },
  { leaf: /silencieux/, mesh: 'muffler' },
  { leaf: /^carter d'huile$/, mesh: 'oil-pan' },
  { leaf: /pompe a huile/, mesh: 'oil-pump' },
  { leaf: /collecteur d'admission/, mesh: 'intake-manifold-upper', variants: [{ when: /inferieur/, mesh: 'intake-manifold-lower' }] },
  { leaf: /soupape pcv|reniflard/, mesh: 'pcv-valve' },
  { leaf: /^ventilation$/, within: /carter de vilebrequin/, mesh: 'pcv-valve' },

  { leaf: /segment racleur|racleur d'huile/, mesh: 'oil-control-ring' },
  { leaf: /segment d'etancheite/, mesh: 'scraper-ring' },
  { leaf: /segment/, mesh: 'compression-ring' },
  { leaf: /axe de piston/, mesh: 'wrist-pin', approximate: false },
  { leaf: /piston/, mesh: 'piston' },
  { leaf: /^bielles?\b/, mesh: 'connecting-rod' },
  { leaf: /^vilebrequin$/, mesh: 'crankshaft' },

  { leaf: /^support moteur|amortisseurs? de support moteur/, mesh: 'engine-mount' },
  { leaf: /^arbre a cames/, mesh: 'intake-cam', variants: [{ when: /echappement/, mesh: 'exhaust-cam' }] },
  { leaf: /^soupapes?\b.*accessoires/, within: /distribution|moteur/, mesh: 'intake-valve', variants: [{ when: /echappement/, mesh: 'exhaust-valve' }] },
  { leaf: /^reglage de soupape|^culbuteur/, mesh: 'hla' },
  { leaf: /^guide de soupape/, mesh: 'valve-spring' },
  { leaf: /^corps papillon|^papillon des gaz/, mesh: 'throttle-body' },
  { leaf: /^turbocompresseur|^compresseur\/composants/, mesh: 'efr-turbo' },
  { leaf: /^collecteur$/, within: /^echappement/, mesh: 'stock-manifold' },
  { leaf: /^tuyaux$/, within: /^echappement/, mesh: 'oem-mid-pipe' },
  { leaf: /^reservoir de carburant/, mesh: 'fuel-tank' },
  { leaf: /^unite d'alimentation|^pompe d'alimentation/, mesh: 'fuel-sender' },
  { leaf: /^butee de debrayage/, mesh: 'throwout-bearing' },
  { leaf: /^accessoires$/, within: /commande a courroie/, mesh: 'alternator-tensioner' },
  { leaf: /^accessoires \/ composants/, within: /frein|freinage/, mesh: 'brake-hardware-1', variants: [{ when: 'rear', mesh: 'rear-pad-hardware' }] },
  { leaf: /^tuyauterie et brides$/, mesh: 'cooling-hardpipe' },
  { leaf: /^fusee d'essieu$/, mesh: 'front-upright' },
  { leaf: /^cache-soupape/, within: /joints/, mesh: 'valve-cover-gasket' },
  { leaf: /^cache-soupape/, mesh: 'valve-cover' },
  { leaf: /^differentiel/, mesh: 'differential' },
  { leaf: /^palier guide/, mesh: 'pilot-bearing' },
];

// TecDoc product group (generic article) → mesh, read before the category node: one node can hold several
// groups that are different parts ("Direction > Rotules" holds outer tie rod ends and inner axial joints).
// An inner axial joint has no mesh of its own: the outer tie rod end would mislead, so it shows its photo.
// « Rotule de direction » (PT 2066, 2 unchecked articles on the 307) is not confirmed as either: photo too.
export const PRODUCT_GROUP_RULES: readonly { group: RegExp; mesh: MeshId | null; approximate?: boolean }[] = [
  { group: /rotule de direction interieure|rotule axiale/, mesh: null },
  { group: /^rotule de barre de connexion/, mesh: 'outer-tie-rod-end', approximate: false },
  { group: /^rotule de direction$/, mesh: null },
  { group: /^poulie, vilebrequin/, mesh: 'crankshaft-pulley-damper' },
  { group: /^boitier du thermostat/, mesh: 'thermostat-housing' },
  // Other groups of a leaf drawn as one part: a gasket, a module, a pipe, a housing or a bracket is not that part.
  { group: /^joint, filtre a huile|^module de filtre a huile|^tuyau d'aspiration|^boite, filtre a air|^capteur, temperature d'huile|^kit rep\. de cable|^jeu de courroies|^poulie, pompe de direction assistee|^support de palier|^butee elastique/, mesh: null },
];

export function partFamily(category: { path: string; name: string }, dimensions: readonly Dimension[] = [], productGroup: string | null = null): Family {
  const group = productGroup === null ? null : foldText(productGroup);
  const groupRule = group === null ? undefined : PRODUCT_GROUP_RULES.find((r) => r.group.test(group));
  if (groupRule) return groupRule.mesh === null ? { kind: 'photo' } : { kind: 'mesh', mesh: groupRule.mesh, approximate: groupRule.approximate ?? true };

  const path = foldText(category.path);
  const leaf = path.split(' > ').at(-1) ?? path;
  if (/habitacle/.test(path)) return { kind: 'photo' };
  if (/filtre a huile/.test(leaf)) return { kind: 'filter', filter: 'oil' };
  if (/filtre a carburant/.test(leaf) && isCylindrical(dimensions)) return { kind: 'filter', filter: 'fuel' };
  if (/filtre a air/.test(leaf) && isCylindrical(dimensions)) return { kind: 'filter', filter: 'air' };
  if (/frein a tambour|frein de haute performance/.test(path) && /^kit de freins/.test(leaf)) return { kind: 'photo' };
  if (BRAKE.test(path) && !/plaquette/.test(leaf)) return { kind: 'brake', axle: axleFrom(path, dimensions), component: /disque de freins?/.test(path) ? 'disc' : 'other' };

  const rule = MESH_RULES.find((r) => r.leaf.test(leaf) && (!r.within || r.within.test(path)) && !(r.except && r.except.test(path)));
  if (!rule || rule.mesh === null) return { kind: 'photo' };
  const variant = rule.variants?.find((v) => (v.when === 'rear' ? axleFrom(path, dimensions) === 'rear' : v.when.test(leaf)));
  return { kind: 'mesh', mesh: variant?.mesh ?? rule.mesh, approximate: rule.approximate ?? true };
}
