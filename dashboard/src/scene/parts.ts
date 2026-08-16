import type { Iri, PlannedPart } from '../types';
import type { Cta } from './CtaCard';

// Parts manifest view-model (option 2a, "option a" — zero contract change). The manifest renders the
// parts a `proposeActions` CTA already carries in its deterministic `patchTask` payload (real refs/prices
// from the agent's parts tool), so nothing here is faked and the honesty guardrail holds. A partsless
// proposal falls back to the ordinary CtaCard. When the catalog read lands ("option b"), a parts UIAction
// + `availability` can feed the SAME component.

export interface PartsManifestModel {
  /** the task the "Ajouter" button merges the parts onto (deterministic patchTask). */
  taskId: Iri;
  /** the service the parts serve — the CTA headline (e.g. "Vidange à prévoir"). */
  service: string;
  finding?: string;
  parts: PlannedPart[];
}

/** Derive a manifest from a parts-bearing proposal, or null when the CTA carries no parts to itemise. */
export function manifestFromCta(cta: Cta | null): PartsManifestModel | null {
  if (!cta) return null;
  const action = cta.actions.find((a) => a.patchTask && a.patchTask.parts.length > 0);
  if (!action || !action.patchTask) return null;
  return { taskId: action.patchTask.id, service: cta.headline, finding: cta.finding, parts: action.patchTask.parts };
}

/** The manifest total in cents: Σ (unit price × qty). Missing price → 0, missing qty → 1 (never NaN). */
export function manifestTotal(parts: PlannedPart[]): number {
  return parts.reduce((sum, p) => sum + (p.priceCents ?? 0) * (p.qty ?? 1), 0);
}


// A parts-manifest CTA (the deterministic patchTask path — no agent turn). The chip and the agent's
// `showParts` converge on this shape, so the manifest + its "Ajouter à la tâche" merge are identical from
// either source. The server merge dedups by ref, so re-merging a task's own parts (option a) is idempotent.
export function partsCtaFor(service: string, taskId: Iri, parts: PlannedPart[]): Cta {
  return { headline: service, actions: [{ label: 'Ajouter à la tâche', patchTask: { id: taskId, parts } }] };
}
