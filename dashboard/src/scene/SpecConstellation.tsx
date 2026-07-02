import './specconstellation.css';
import { NBSP } from '../format';
import { factLabel } from '../labels';
import { InlineEdit } from '../floor/InlineEdit';
import type { VehicleFact } from '../types';

// The spec constellation (option 2a) — an on-demand agent surface. A `focus.panel === 'specs'` frame
// (from the deterministic "Infos moteur" chip, or the agent's showFact / focusView(specs)) fans the
// vehicle's facts as a cluster of small cards to the car's LEFT, each with a hair leader pointing at the
// car. Never a docked panel: it etches in, then clears when the focus does. Verified (catalog) facts wear
// a ✓; unverified read "· non vérifié". The truth-panel (rung c/d) mirrors every value at full precision.

/** The facts to project: filtered to `keys`, in the vehicle's fact order. Empty `keys` ⇒ the whole sheet. */
export function selectFacts(facts: VehicleFact[], keys: string[]): VehicleFact[] {
  if (keys.length === 0) return facts;
  const wanted = new Set(keys);
  return facts.filter((f) => wanted.has(f.key));
}

// Identity-level fluids only (not the full sheet) — what stays legible with no focus active, so the
// car's core moteur facts are never hidden behind a chip. SceneView passes these as the resting `keys`
// (SpecConstellation runs them through selectFacts internally — one path, no duplicate helper).
export const MOTEUR_REST_KEYS: string[] = ['oil_viscosity', 'oil_type', 'oil_capacity', 'coolant_type'];

// Staggered etch-in delays (§ motion): .08 / .16 / .24 … capped so a long sheet still settles quickly.
const STAGGER_MS = 80;
const MAX_STAGGER = 6;

export function SpecConstellation({
  facts,
  keys,
  title,
  reduced = false,
  edit,
  agentPlaced = false,
  onDismiss,
}: {
  facts: VehicleFact[];
  keys: string[];
  title?: string;
  reduced?: boolean;
  /** Live-hero editing seam: present → each node's value is click-to-edit. Absent → plain text (playground/read-only). */
  edit?: { onEditValue: (key: string, value: string, unit?: string | null) => void };
  /** The agent placed this (a specs focus) vs. the operator's own resting pins — drives the cyan
   * attribution tick + the ✕ dismiss (both absent for resting pins). */
  agentPlaced?: boolean;
  /** Dismiss the agent-placed focus (clears it). Absent → no ✕ (resting pins are persistent). */
  onDismiss?: () => void;
}) {
  const nodes = selectFacts(facts, keys);
  if (nodes.length === 0) return null;
  return (
    <div className="at-spec" data-reduced={reduced ? 'true' : undefined} role="group" aria-label={title ?? 'Spécifications'}>
      <div className="at-proj-head">
        {agentPlaced ? (
          <span className="at-proj-tick" aria-label="placé par Le Noyau" role="img" />
        ) : (
          <span className="at-spec-glyph" aria-hidden="true">◇</span>
        )}
        <div className="at-spec-head">{title ?? 'Spécifications'}</div>
        {onDismiss && (
          <button type="button" className="at-proj-dismiss" onClick={onDismiss} aria-label="Fermer les spécifications">
            ✕
          </button>
        )}
      </div>
      <div className="at-spec-nodes">
        {nodes.map((f, i) => (
          <div
            key={f.key}
            className="at-spec-node"
            style={reduced ? undefined : { animationDelay: `${Math.min(i, MAX_STAGGER) * STAGGER_MS}ms` }}
          >
            <span className="at-spec-label">{factLabel(f.key)}</span>
            <span className="at-spec-value">
              {edit ? (
                <InlineEdit
                  value={f.value}
                  ariaLabel={`Modifier ${factLabel(f.key)}`}
                  onCommit={(next) => edit.onEditValue(f.key, next, f.unit)}
                />
              ) : (
                f.value
              )}
              {f.unit ? `${NBSP}${f.unit}` : ''}{' '}
              {f.verified ? (
                <span className="at-spec-ok" aria-label="vérifié">
                  ✓
                </span>
              ) : (
                <span className="at-spec-unver">· non vérifié</span>
              )}
            </span>
            <span className="at-spec-lead" aria-hidden="true" />
          </div>
        ))}
      </div>
    </div>
  );
}
