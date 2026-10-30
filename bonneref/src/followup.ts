import { cotesLine } from './result';
import { variantNotes, type Selection } from './variants';
import type { Cluster, Narrowing } from './types';

// The on-screen result a follow-up continues (POST /agent/turn `summary`). The server caps it at
// ~2000 chars; this stays under so nothing is cut mid-cluster.
const CAP = 1900;
const MAX_OE = 3;

function clusterLine(c: Cluster, scope: Narrowing['applicabilityScope']): string {
  const parts = [`[${c.id}] ${c.representative.brand} ${c.representative.number}`];
  const oe = c.oeNumbers.slice(0, MAX_OE).map((r) => `${r.brand} ${r.number}`);
  if (oe.length > 0) parts.push(`OE ${oe.join(', ')}`);
  const cotes = cotesLine(c.dimensions);
  if (cotes) parts.push(cotes);
  const shared = c.applicability.map((a) => `${a.name} ${a.values.join(' / ')}`).join(' ; ');
  if (scope === 'vehicle') {
    if (shared) parts.push(`applicabilité sur ce véhicule : ${shared}`);
    const notes = variantNotes(c);
    if (notes.length > 0) parts.push(`par variante : ${notes.map((x) => `${x.ref} : ${x.text}`).join(' ; ')}`);
  } else if (shared) parts.push(`applicabilité (par article, pas par véhicule) : ${shared}`);
  return parts.join(' — ');
}

const chosenBy = (s: Selection, scope: Narrowing['applicabilityScope']): string | null =>
  s.status === 'agent' && s.verdict
    ? `retenue d’après ${s.verdict.basis || 'ta réponse précédente'}, indication (applicabilité ${scope === 'vehicle' ? 'de ce véhicule' : 'par article'})`
    : s.status === 'answer'
      ? 'choisie par la réponse à la question'
      : s.status === 'user'
        ? 'choisie par l’utilisateur, non confirmée par le catalogue'
        : null;

// `selection` comes from selectVariant: an agent verdict reaches the agent only once the server vouched for it.
export function resultSummary(categoryLabel: string, n: Narrowing, selection: Selection | null): string {
  const ids = `categoryId ${n.categoryId}${n.productGroup ? `, productGroupId ${n.productGroup.id}` : ''}`;
  const head = `${categoryLabel} (${ids}) : ${n.clusters.length} pièce${n.clusters.length > 1 ? 's' : ''} distincte${n.clusters.length > 1 ? 's' : ''}`;
  const how = selection?.cluster ? chosenBy(selection, n.applicabilityScope) : null;
  const chosen = how ? `, ${how} : [${selection!.cluster!.id}]` : '';
  let text = `${head}${chosen}.`;
  for (const c of n.clusters) {
    const line = ` ${clusterLine(c, n.applicabilityScope)}.`;
    if (text.length + line.length > CAP) {
      text += ' …';
      break;
    }
    text += line;
  }
  return text;
}
