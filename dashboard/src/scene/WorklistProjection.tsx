import './worklist.css';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { serviceLabel } from '../labels';
import { signalTag } from '../derive';
import type { Worklist } from './worklist';

// The worklist projection (D3) — the answer to « qu'est-ce que j'ai à faire ? », in the shared
// projection column at the car's RIGHT, beside the spec constellation and the carnet.
//
// It exists because `focusView {panel:'tasks'}` used to project NOTHING: the scene dimmed and the
// operator was left looking at a darkened car, with the answer supposedly readable in the persistent
// left rail they were already looking at. A focus that dims must put something in the freed
// attention; this is that something.
//
// Deliberately NOT a second checklist. Rows are read-only — every one of them is editable two
// hundred pixels to the left, in the rail, which stays lit. This is the agent's CUT of that rail:
// these ones, in this order, for this question.

export function WorklistProjection({
  model,
  reduced = false,
  onDismiss,
}: {
  model: Worklist;
  reduced?: boolean;
  onDismiss?: () => void;
}) {
  const [locale] = useLocale();
  const t = useT();
  if (model.items.length === 0 && model.missing.length === 0) return null;
  const late = model.items.filter((i) => i.due?.overdue && !i.done).length;
  return (
    <aside className="at-worklist" data-reduced={reduced ? 'true' : undefined} role="group" aria-label={t.proj.worklist}>
      <div className="at-proj-head">
        <span className="at-proj-tick" aria-label={t.proj.placedByNoyau} role="img" />
        <div className="at-worklist-head">{t.proj.worklistHead}</div>
        {onDismiss && (
          <button type="button" className="at-proj-dismiss" onClick={onDismiss} aria-label="Fermer la liste">
            ✕
          </button>
        )}
      </div>

      <ul className="at-worklist-rows" role="list">
        {model.items.map((item, i) => {
          const tag = item.due ? signalTag(!!item.due.overdue && !item.done, item.signal, locale) : null;
          const parts = item.task?.parts?.length ?? 0;
          return (
            <li
              key={item.id}
              className="at-worklist-row"
              data-signal={item.kind === 'gap' ? 'unknown' : item.signal}
              style={reduced ? undefined : { animationDelay: `${Math.min(i, 4) * 60 + 50}ms` }}
            >
              <span className="at-worklist-dot" aria-hidden="true" />
              <span className="at-worklist-body">
                <span className="at-worklist-svc">{item.label}</span>
                <span className="at-worklist-meta">
                  {item.kind === 'gap' ? t.proj.neverLogged : (item.horizon ?? t.forecast.noDueDateLower)}
                  {parts > 0 && t.proj.refCount(parts)}
                </span>
              </span>
              {tag && tag.tone !== 'ok' && <span className={`at-worklist-tag at-worklist-tag--${tag.tone}`}>{tag.text}</span>}
            </li>
          );
        })}
      </ul>

      {/* Honesty seam: a spotlighted service this vehicle carries no échéance for is NAMED. Silence
          here used to make a bad key and a working panel look identical. */}
      {model.missing.length > 0 && (
        <p className="at-worklist-missing">
          Pas de suivi ici&nbsp;: {model.missing.map((k) => serviceLabel(k, locale)).join(' · ')}
        </p>
      )}

      <div className="at-worklist-foot">
        {model.items.length} point{model.items.length > 1 ? 's' : ''}
        {late > 0 && <span className="at-worklist-late"> · {late} en retard</span>}
      </div>
      <span className="at-worklist-lead" aria-hidden="true" />
    </aside>
  );
}
