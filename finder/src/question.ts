import { NNBSP, formatRange, formatValue } from './format';
import { foldText } from './search';
import type { Narrowing, QuestionOption } from './types';

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
  [/^hauteur/, 'Mesurez de la base au sommet, joint compris.'],
  [/^diametre/, 'Mesurez au pied à coulisse, ou lisez la valeur gravée.'],
  [/^epaisseur/, 'Mesurez au pied à coulisse.'],
  [/^thread|^filetage/, 'Comparez avec le filetage de la pièce démontée.'],
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
      text: `${n.clusters.length} références possibles, sans cote distinctive. Laquelle est montée ?`,
      hint: 'Comparez avec la réf. gravée sur la pièce démontée.',
      answers: n.clusters.map((c) => ({ label: `${c.representative.brand} ${c.representative.number}`, clusterId: c.id })),
    };
  }

  const byValue = new Map<string, string>();
  const spans = new Map<string, string>();
  for (const { value, clusterId, min, max } of divergence.values) {
    const held = byValue.get(value);
    if (held === undefined || (articles.get(clusterId) ?? 0) > (articles.get(held) ?? 0)) {
      byValue.set(value, clusterId);
      spans.set(value, min !== null && max !== null && min !== max ? formatRange(min, max) : formatValue(value));
    }
  }
  const numeric = divergence.unit !== null;
  const values = [...byValue.keys()].sort((a, b) => (numeric ? Number(a) - Number(b) : a.localeCompare(b, 'fr')));

  const prefix = /^diametre/.test(divergence.key) && numeric ? `Ø${NNBSP}` : '';
  const unit = numeric && divergence.unit ? `${NNBSP}${divergence.unit}` : '';
  const shown = values.map((v) => `${prefix}${spans.get(v) ?? formatValue(v)}`);

  return {
    text: `${divergence.label}${ofNoun(categoryLabel)} : ${joinOu(shown)}${unit} ?`,
    hint: hintFor(divergence.key),
    answers: values.map((v, i) => ({ label: `${shown[i]}${unit}`, clusterId: byValue.get(v) ?? '' })),
  };
}

export type AskChoice = { kind: 'agent'; label: string; value: string } | { kind: 'plate'; label: string };

export const PLATE_LABEL = 'Donner la plaque';

// The plate shortcut is added here, not by the agent, so no question can forget it; it sits outside the agent's six.
export function askChoices(options: QuestionOption[]): AskChoice[] {
  return [...options.map((o): AskChoice => ({ kind: 'agent', label: o.label, value: o.value })), { kind: 'plate', label: PLATE_LABEL }];
}
