import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { tickMarks } from './gauge';
import type { NoyauState } from './state';
import './noyau.css';

const VIEW = 132;
const C = VIEW / 2;

const GLYPH: Record<string, string> = { pulse: '●', narration: '·', alarm: '▲', done: '◆' };

// Per-tool chip glyphs — each differs by SHAPE (not colour alone): a nut, a clock, a notebook, a
// pennant, a plate, a droplet, a pencil, a save-mark, a fleet grid, a globe. Unknown → a diamond.
// All stroke `currentColor` (inherits the chip's mono/amber tone) at a hairline weight.
function StepGlyph({ tool }: { tool: string }) {
  const paths: Record<string, ReactNode> = {
    get_service_parts: <path d="M6 1.3 10.2 3.7v4.6L6 10.7 1.8 8.3V3.7z" />, // hexagonal nut
    list_due_maintenance: (
      <>
        <circle cx="6" cy="6" r="4.4" />
        <path d="M6 3.4V6l1.9 1.2" />
      </>
    ), // forecast clock
    get_history: (
      <>
        <rect x="2.6" y="1.8" width="6.8" height="8.4" rx="1" />
        <path d="M4.4 4.2h3.4M4.4 6h3.4M4.4 7.8h2.2" />
      </>
    ), // carnet
    plan_maintenance: (
      <>
        <path d="M3.2 1.6v8.8" />
        <path d="M3.2 2.2h5.4L7.2 4l1.4 1.8H3.2z" />
      </>
    ), // planning pennant
    lookup_plate: (
      <>
        <rect x="1.6" y="3.6" width="8.8" height="4.8" rx="1" />
        <path d="M3.4 6h5.2" />
      </>
    ), // plate
    get_motul_oil: <path d="M6 1.4c2 2.5 3 4.1 3 5.5a3 3 0 0 1-6 0c0-1.4 1-3 3-5.5z" />, // droplet
    update_vehicle: <path d="M8.3 2.1 9.9 3.7 4.4 9.2H2.8V7.6z" />, // pencil
    log_maintenance: (
      <>
        <circle cx="6" cy="6" r="4.4" />
        <path d="M6 3.9v4.2M3.9 6h4.2" />
      </>
    ), // record / plus
    list_vehicles: (
      <>
        <rect x="1.9" y="1.9" width="3.4" height="3.4" rx="0.7" />
        <rect x="6.7" y="1.9" width="3.4" height="3.4" rx="0.7" />
        <rect x="1.9" y="6.7" width="3.4" height="3.4" rx="0.7" />
        <rect x="6.7" y="6.7" width="3.4" height="3.4" rx="0.7" />
      </>
    ), // fleet grid
    web_search: (
      <>
        <circle cx="6" cy="6" r="4.4" />
        <path d="M1.6 6h8.8M6 1.6c1.6 1.7 1.6 7.1 0 8.8M6 1.6c-1.6 1.7-1.6 7.1 0 8.8" />
      </>
    ), // globe
  };
  return (
    <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" strokeWidth="1.1" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      {paths[tool] ?? <path d="M6 1.8 10.2 6 6 10.2 1.8 6z" />}
    </svg>
  );
}
const SIGNAL_FR: Record<string, string> = { green: 'vert', amber: 'ambre', red: 'rouge' };

/**
 * Le Noyau — the agent embodied as a diagnostic instrument: a machined lens/iris that READS the car.
 * The aperture (pupil) dilates when listening, contracts to a focused point when working, and settles
 * to the result color when done — so the four states differ by APERTURE (a shape cue), not color alone.
 * Pure presentation: it renders whatever NoyauState says. Hide it and not one value is lost — the orb
 * conveys urgency, the truth-panel/plan-list carry the data.
 */
export function Noyau({
  state,
  reduced = false,
  onClearEmber,
  onEmberActivate,
}: {
  state: NoyauState;
  reduced?: boolean;
  onClearEmber?: () => void;
  /** C2: clicking the ember OPENS the à-décider inbox (which also mutes the badge). When absent (the
   *  Stage-A playground has no inbox) the click falls back to a bare badge acknowledge. */
  onEmberActivate?: () => void;
}) {
  const ticks = useMemo(() => tickMarks(60, C, C, 53, 59, 5), []);
  const [rings, setRings] = useState<number[]>([]);
  const [journalOpen, setJournalOpen] = useState(false);
  const prevPulse = useRef(0);

  useEffect(() => {
    if (!reduced && state.pulseCount > prevPulse.current) {
      const id = state.pulseCount;
      setRings((r) => [...r, id]);
      window.setTimeout(() => setRings((r) => r.filter((x) => x !== id)), 1000);
    }
    prevPulse.current = state.pulseCount;
  }, [state.pulseCount, reduced]);

  // Narration crossfade: the outgoing line fades up-and-out (aria-hidden, so it is never
  // re-announced) while the incoming line etches in on the aria-live span — one motion grammar
  // for both. Reduced motion → instant swap: no exit line is ever staged.
  const [exiting, setExiting] = useState<{ id: number; text: string } | null>(null);
  const prevNarration = useRef(state.narration);
  const exitSeq = useRef(0);
  useEffect(() => {
    const prev = prevNarration.current;
    prevNarration.current = state.narration;
    if (reduced || prev === state.narration || !prev) return;
    const id = ++exitSeq.current;
    setExiting({ id, text: prev });
    const timeout = window.setTimeout(() => setExiting((e) => (e?.id === id ? null : e)), 360);
    return () => window.clearTimeout(timeout);
  }, [state.narration, reduced]);

  const stop = (color: string) => ({ stopColor: color });

  return (
    <div
      className="noyau-stage"
      data-phase={state.phase}
      data-result={state.result ?? undefined}
      data-reduced={reduced ? 'true' : undefined}
    >
      {/* orb + ember are SIBLINGS in a positioned wrap: the ember is a real <button> (keyboard-reachable,
          opens the à-décider inbox) and must NOT nest inside the role="button" orb (an ARIA violation). */}
      <div className="noyau-orb-wrap">
      <div
        className="noyau-orb"
        role="button"
        tabIndex={0}
        aria-label={`Le Noyau — ${state.phase}${state.result ? ` · ${SIGNAL_FR[state.result]}` : ''} — journal`}
        aria-expanded={journalOpen}
        onClick={() => setJournalOpen((o) => !o)}
        onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && setJournalOpen((o) => !o)}
      >
        <svg className="noyau-lens" viewBox={`0 0 ${VIEW} ${VIEW}`} aria-hidden="true">
          <defs>
            <radialGradient id="nx-bezel" cx="38%" cy="30%" r="80%">
              <stop offset="0%" style={stop('var(--nx-chrome-hi)')} />
              <stop offset="60%" style={stop('var(--nx-chrome)')} />
              <stop offset="100%" style={stop('#0a0b0e')} />
            </radialGradient>
            <radialGradient id="nx-pupil" cx="50%" cy="50%" r="50%">
              <stop offset="0%" style={stop('var(--nx-fil-core)')} />
              <stop offset="70%" style={stop('var(--nx-fil-edge)')} />
              <stop offset="100%" style={{ stopColor: 'var(--nx-fil-edge)', stopOpacity: 0 }} />
            </radialGradient>
            <filter id="nx-blur">
              <feGaussianBlur stdDeviation="4" />
            </filter>
          </defs>

          {/* housing + lens well */}
          <circle cx={C} cy={C} r={61} fill="url(#nx-bezel)" />
          <circle cx={C} cy={C} r={53} fill="#0b0c0f" stroke="var(--nx-seam)" strokeWidth={0.75} />

          {/* gauge ticks — the instrument bezel */}
          <g className="noyau-ticks">
            {ticks.map((t, i) => (
              <line
                key={i}
                className={t.major ? 'noyau-tick noyau-tick--major' : 'noyau-tick'}
                x1={t.x1}
                y1={t.y1}
                x2={t.x2}
                y2={t.y2}
              />
            ))}
          </g>

          {/* lens refraction rings + faint reticle crosshair */}
          <circle className="noyau-lens-ring" cx={C} cy={C} r={44} fill="none" />
          <circle className="noyau-lens-ring" cx={C} cy={C} r={33} fill="none" />
          <line className="noyau-cross" x1={C - 50} y1={C} x2={C + 50} y2={C} />
          <line className="noyau-cross" x1={C} y1={C - 50} x2={C} y2={C + 50} />

          {/* scanning reticle — drifts at rest, locks/spins while working */}
          <circle className="noyau-scan" cx={C} cy={C} r={40} fill="none" strokeDasharray="2 9" />

          {/* the glint sweeps across the glass */}
          <ellipse className="noyau-glint" cx={C} cy={C} rx={12} ry={42} fill="rgba(255,255,255,0.4)" filter="url(#nx-blur)" />

          {/* the iris aperture + glowing pupil — dilates/contracts per state */}
          <circle className="noyau-iris" cx={C} cy={C} r={20} fill="none" stroke="var(--nx-emissive)" strokeWidth={1.25} />
          <circle className="noyau-pupil" cx={C} cy={C} r={15} fill="url(#nx-pupil)" filter="url(#nx-blur)" />

          {/* listening: a ring traces the circumference (a signal being received) */}
          <circle className="noyau-rim" cx={C} cy={C} r={48} fill="none" stroke="var(--nx-cyan-hi)" strokeWidth={1.2} strokeDasharray="3 5" />

          {/* red done: the reticle flares as a fault lamp */}
          <circle className="noyau-fault" cx={C} cy={C} r={50} fill="none" stroke="var(--nx-red-hi)" strokeWidth={1.5} />
        </svg>

        <div className="noyau-pulse-layer" aria-hidden="true">
          {rings.map((id) => (
            <span className="noyau-pulse-ring" key={id} />
          ))}
          {state.phase === 'done' && <span className="noyau-exhale" />}
        </div>
      </div>

        {state.ember > 0 && (
          <button
            type="button"
            className="noyau-ember"
            aria-label={`${state.ember} décision(s) en attente — ouvrir « à décider »`}
            aria-live="polite"
            onClick={(e) => {
              e.stopPropagation();
              (onEmberActivate ?? onClearEmber)?.();
            }}
          >
            {state.ember}
          </button>
        )}
      </div>

      <svg className="noyau-cradle" viewBox="0 0 120 26" aria-hidden="true">
        <ellipse cx="60" cy="8" rx="46" ry="7" fill="#1c1e22" stroke="rgba(180,196,210,0.18)" />
        <rect x="30" y="9" width="60" height="11" rx="3" fill="#16181c" stroke="rgba(180,196,210,0.12)" />
        <ellipse cx="60" cy="8" rx="30" ry="4" fill="var(--nx-emissive)" opacity="0.12" />
      </svg>

      <div className="noyau-narration" aria-live="polite">
        {exiting && (
          <span className="noyau-narration-exit" aria-hidden="true">
            {exiting.text}
          </span>
        )}
        {state.narration && <span key={state.narration}>{state.narration}</span>}
      </div>

      {/* Typed tool-step chips: one per tool call as the frames arrive, then they collapse toward the
          journal on turn end (the data is already in the 🪵 drawer). aria-hidden — the narration line
          above already voices each step on the aria-live region, so the chips never double-announce. */}
      {state.steps.length > 0 && (
        <div className="noyau-steps" aria-hidden="true">
          {state.steps.map((s) => (
            <span className="noyau-step" key={s.id} data-state={s.state}>
              <StepGlyph tool={s.tool} />
              <span className="noyau-step-label">{s.state === 'error' ? 'échec' : s.label}</span>
            </span>
          ))}
        </div>
      )}

      <button className="noyau-journal-toggle" aria-expanded={journalOpen} onClick={() => setJournalOpen((o) => !o)}>
        {journalOpen ? 'fermer le journal' : 'journal'}
      </button>

      {journalOpen && (
        <aside className="noyau-journal">
          {state.journal.length === 0 ? (
            <div className="noyau-journal-empty">Aucune activité.</div>
          ) : (
            [...state.journal].reverse().map((e) => (
              <div className="noyau-journal-row" key={e.id}>
                <span className="noyau-journal-glyph" data-kind={e.kind}>
                  {GLYPH[e.kind]}
                </span>
                <span className="noyau-journal-msg">{e.message}</span>
                {e.signal && <span className="noyau-journal-sig">{SIGNAL_FR[e.signal]}</span>}
                <span className="noyau-journal-elapsed">+{(e.elapsedMs / 1000).toFixed(0)}s</span>
              </div>
            ))
          )}
        </aside>
      )}
    </div>
  );
}
