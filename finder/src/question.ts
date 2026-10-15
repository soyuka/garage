import { NNBSP, formatValue } from './format';
import { foldText } from './search';
import type { Narrowing } from './types';

export interface Answer {
  label: string;
  clusterId: string;
}

export interface Question {
  text: string;
  hint: string | null;
  answers: Answer[];
}

const FEMININE = new Set(['plaquette', 'bougie', 'courroie', 'pompe', 'bobine', 'rotule', 'biellette', 'durite', 'sonde', 'batterie', 'cartouche', 'machoire', 'garniture', 'butee', 'chaine']);

function ofNoun(categoryLabel: string): string {
  const noun = (categoryLabel.trim().split(/\s+/)[0] ?? '').toLowerCase();
  if (noun === '') return '';
  if (/^[aeiouyhéèêàâîôû]/i.test(noun)) return ` de l’${noun}`;
  return FEMININE.has(foldText(noun)) ? ` de la ${noun}` : ` du ${noun}`;
}

const HINTS: [RegExp, string][] = [
  [/^hauteur/, 'Mesurez l’ancienne pièce de la base au sommet, joint compris.'],
  [/^diametre/, 'Mesurez le diamètre de l’ancienne pièce, ou lisez-le gravé dessus.'],
  [/^epaisseur/, 'Mesurez l’épaisseur de l’ancienne pièce au pied à coulisse.'],
  [/^thread|^filetage/, 'Comparez le filetage de l’ancienne pièce.'],
];

const hintFor = (key: string): string | null => HINTS.find(([re]) => re.test(key))?.[1] ?? null;

function joinOu(items: string[]): string {
  if (items.length <= 1) return items.join('');
  return `${items.slice(0, -1).join(', ')} ou ${items.at(-1)}`;
}

export function buildQuestion(n: Narrowing, categoryLabel: string): Question | null {
  if (n.clusters.length < 2) return null;
  const articles = new Map(n.clusters.map((c) => [c.id, c.articleCount]));

  const divergence = n.differsOn.find((d) => new Set(d.values.map((v) => v.value)).size > 1);
  if (!divergence) {
    return {
      text: `${n.clusters.length} pièces possibles, sans cote qui les sépare : laquelle ressemble à l’ancienne ?`,
      hint: 'Comparez la référence gravée sur l’ancienne pièce.',
      answers: n.clusters.map((c) => ({ label: `${c.representative.brand} ${c.representative.number}`, clusterId: c.id })),
    };
  }

  const byValue = new Map<string, string>();
  for (const { value, clusterId } of divergence.values) {
    const held = byValue.get(value);
    if (held === undefined || (articles.get(clusterId) ?? 0) > (articles.get(held) ?? 0)) byValue.set(value, clusterId);
  }
  const numeric = divergence.unit !== null;
  const values = [...byValue.keys()].sort((a, b) => (numeric ? Number(a) - Number(b) : a.localeCompare(b, 'fr')));

  const prefix = /^diametre/.test(divergence.key) && numeric ? `Ø${NNBSP}` : '';
  const unit = numeric && divergence.unit ? `${NNBSP}${divergence.unit}` : '';
  const shown = values.map((v) => `${prefix}${formatValue(v)}`);

  return {
    text: `${divergence.label}${ofNoun(categoryLabel)} : ${joinOu(shown)}${unit} ?`,
    hint: hintFor(divergence.key),
    answers: values.map((v, i) => ({ label: `${shown[i]}${unit}`, clusterId: byValue.get(v) ?? '' })),
  };
}
