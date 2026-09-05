import { useEffect, useRef } from 'react';
import './specconstellation.css';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { AgentMark } from '../AgentMark';
import { NBSP } from '../format';
import { factLabel } from '../labels';
import { InlineEdit } from '../floor/InlineEdit';
import { isAgentAuthored } from '../provenance';
import type { SpecNode } from '../focus';
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
  nodes,
  onHoverNode,
  focusKey = null,
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
  /** Projected, already-formatted nodes (a fetched surface: fluid capacities, the SIV carte grise). When
   * set, these ARE the content — read-only, with an optional secondary spec line — bypassing facts/keys. */
  nodes?: SpecNode[];
  /** Fired on a card's hover/focus in/out — the fluid station marks the matching cylinder in the
   *  operator hue (§ "Fluids — the volumes"). Absent → no station hover wiring. */
  onHoverNode?: (key: string | null) => void;
  /** A cylinder picked on the stage — that card scrolls into view and wears the hover hairline for as
   * long as the pick stands (§ "Picking — the body points back"). Absent/null → no card is picked. */
  focusKey?: string | null;
}) {
  const [locale] = useLocale();
  const t = useT();
  const nodesRef = useRef<HTMLDivElement>(null);
  const factNodes = selectFacts(facts, keys);
  const count = nodes ? nodes.length : factNodes.length;

  useEffect(() => {
    if (!focusKey) return;
    const el = nodesRef.current?.querySelector<HTMLElement>(`[data-key="${CSS.escape(focusKey)}"]`);
    el?.scrollIntoView({ block: 'center', behavior: reduced ? 'auto' : 'smooth' });
  }, [focusKey, reduced]);

  if (count === 0) return null;
  const delay = (i: number) => (reduced ? undefined : { animationDelay: `${Math.min(i, MAX_STAGGER) * STAGGER_MS}ms` });
  return (
    <div className="at-spec" data-reduced={reduced ? 'true' : undefined} role="group" aria-label={title ?? t.proj.specs}>
      <div className="at-proj-head">
        {agentPlaced ? (
          <span className="at-proj-tick" aria-label={t.proj.placedByNoyau} role="img" />
        ) : (
          <span className="at-spec-glyph" aria-hidden="true">◇</span>
        )}
        <div className="at-spec-head">{title ?? t.proj.specs}</div>
        {onDismiss && (
          <button type="button" className="at-proj-dismiss" onClick={onDismiss} aria-label={t.proj.closeSpecs}>
            ✕
          </button>
        )}
      </div>
      <div className="at-spec-nodes" ref={nodesRef}>
        {nodes
          ? nodes.map((n, i) => (
              <div
                key={n.key}
                className="at-spec-node"
                data-key={n.key}
                data-picked={focusKey === n.key ? 'true' : undefined}
                style={delay(i)}
                tabIndex={onHoverNode ? 0 : undefined}
                onMouseEnter={onHoverNode ? () => onHoverNode(n.key) : undefined}
                onMouseLeave={onHoverNode ? () => onHoverNode(null) : undefined}
                onFocus={onHoverNode ? () => onHoverNode(n.key) : undefined}
                onBlur={onHoverNode ? () => onHoverNode(null) : undefined}
              >
                <span className="at-spec-label">{n.label}</span>
                <span className="at-spec-value">
                  {n.value}{' '}
                  {n.verified && (
                    <span className="at-spec-ok" aria-label={t.proj.verified}>
                      ✓
                    </span>
                  )}
                </span>
                {n.info && <span className="at-spec-info">{n.info}</span>}
                <span className="at-spec-lead" aria-hidden="true" />
              </div>
            ))
          : factNodes.map((f, i) => (
              <div
                key={f.key}
                className="at-spec-node"
                data-key={f.key}
                data-picked={focusKey === f.key ? 'true' : undefined}
                style={delay(i)}
                tabIndex={onHoverNode ? 0 : undefined}
                onMouseEnter={onHoverNode ? () => onHoverNode(f.key) : undefined}
                onMouseLeave={onHoverNode ? () => onHoverNode(null) : undefined}
                onFocus={onHoverNode ? () => onHoverNode(f.key) : undefined}
                onBlur={onHoverNode ? () => onHoverNode(null) : undefined}
              >
                <span className="at-spec-label">{factLabel(f.key, locale)}</span>
                <span className="at-spec-value">
                  {edit ? (
                    <InlineEdit value={f.value} ariaLabel={`Modifier ${factLabel(f.key, locale)}`} onCommit={(next) => edit.onEditValue(f.key, next, f.unit)} />
                  ) : (
                    f.value
                  )}
                  {f.unit ? `${NBSP}${f.unit}` : ''}{' '}
                  {f.verified ? (
                    <span className="at-spec-ok" aria-label={t.proj.verified}>
                      ✓
                    </span>
                  ) : (
                    <>
                      <span className="at-spec-unver">{t.proj.unverified}</span>
                      {isAgentAuthored(f.source) && <AgentMark />}
                    </>
                  )}
                </span>
                <span className="at-spec-lead" aria-hidden="true" />
              </div>
            ))}
      </div>
    </div>
  );
}
