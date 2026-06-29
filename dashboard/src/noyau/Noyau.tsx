import { useEffect, useMemo, useRef, useState } from 'react';
import { tickMarks } from './gauge';
import type { NoyauState } from './state';
import './noyau.css';

const VIEW = 132;
const C = VIEW / 2;

const GLYPH: Record<string, string> = { pulse: '●', narration: '·', alarm: '▲', done: '◆' };
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
}: {
  state: NoyauState;
  reduced?: boolean;
  onClearEmber?: () => void;
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

  const stop = (color: string) => ({ stopColor: color });

  return (
    <div
      className="noyau-stage"
      data-phase={state.phase}
      data-result={state.result ?? undefined}
      data-reduced={reduced ? 'true' : undefined}
    >
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

        {state.ember > 0 && (
          <span
            className="noyau-ember"
            role="status"
            aria-label={`${state.ember} notification(s)`}
            onClick={(e) => {
              e.stopPropagation();
              onClearEmber?.();
            }}
          >
            {state.ember}
          </span>
        )}
      </div>

      <svg className="noyau-cradle" viewBox="0 0 120 26" aria-hidden="true">
        <ellipse cx="60" cy="8" rx="46" ry="7" fill="#1c1e22" stroke="rgba(180,196,210,0.18)" />
        <rect x="30" y="9" width="60" height="11" rx="3" fill="#16181c" stroke="rgba(180,196,210,0.12)" />
        <ellipse cx="60" cy="8" rx="30" ry="4" fill="var(--nx-emissive)" opacity="0.12" />
      </svg>

      <div className="noyau-narration" aria-live="polite">
        {state.narration && <span key={state.narration}>{state.narration}</span>}
      </div>

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
