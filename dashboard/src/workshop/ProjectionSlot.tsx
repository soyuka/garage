import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { isArchived } from './archive';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { rollupCarnet } from '../carnet';
import { formatDate, formatEuros, formatReading } from '../format';
import { factLabel, serviceLabel } from '../labels';
import { buildShoppingList } from '../scene/shopping';
import type { DocumentSpec, PlannedTask, VehicleBundle } from '../types';
import { jobTitle, jobWhen, reading, sameLabel, sentenceCase } from './describe';
import { JobEditActions } from './Editors';
import { Close, Open } from './Icons';
import { FactRows, NodeRows, PartRows } from './Module';
import { urgentJob, type JobItem, type Modules } from './modules';
import { StateMark } from './StateMark';
import type { Projection, ViewAction, ViewState } from './view';

// Matches the exit keyframe length in workshop.css; the outgoing card is unmounted after it.
const EXIT_MS = 260;
const URGENT_PARTS = 3;

interface SlotProps {
  view: ViewState;
  bundle: VehicleBundle;
  modules: Modules;
  onClose: () => void;
  onOpenDocument: (doc: DocumentSpec) => void;
  actionsDisabled: boolean;
  /** Prefill the command bar with a request about this job. */
  /** Turn a forecast item into a planned task; absent where planning is not wired. */
  onPlan?: (job: JobItem) => void;
  /** Only the front plane's slot announces its swaps. */
  live: boolean;
  onView: (action: ViewAction) => void;
}

function titleOf(projection: Projection, view: ViewState, t: ReturnType<typeof useT>, locale: ReturnType<typeof useLocale>[0]): string {
  switch (projection.kind) {
    case 'facts':
      return projection.title ?? t.workshop.projection.facts;
    case 'panel':
      return t.workshop.projection.panel[projection.panel];
    case 'history':
      return t.workshop.projection.history;
    case 'parts':
      return t.workshop.projection.parts(serviceLabel(projection.service, locale));
    case 'assembly':
      return t.workshop.projection.assembly(t.region[projection.region]);
    case 'document':
      return view.document?.title ?? t.workshop.projection.document;
  }
}

function Body({ projection, view, bundle, modules, onOpenDocument }: Omit<SlotProps, 'onClose'> & { projection: Projection }) {
  const t = useT();
  const [locale] = useLocale();
  const facts = bundle.vehicle.facts ?? [];

  switch (projection.kind) {
    case 'facts': {
      if (projection.nodes && projection.nodes.length > 0) return <NodeRows nodes={projection.nodes} />;
      const keys = new Set(projection.keys);
      const shown = keys.size > 0 ? facts.filter((f) => keys.has(f.key)) : facts;
      return shown.length > 0 ? <FactRows facts={shown} /> : <p className="w-muted">{t.workshop.facts.empty}</p>;
    }

    case 'panel': {
      if (projection.panel === 'specs') return facts.length > 0 ? <FactRows facts={facts} spotlit={projection.keys} /> : <p className="w-muted">{t.workshop.facts.empty}</p>;
      if (projection.panel === 'docs') {
        return modules.documentsRecents.length > 0 ? (
          <ul className="w-rows">
            {modules.documentsRecents.map((d) => (
              <li key={d.id} className="w-row">
                <span className="w-row-main" title={d.title}>{d.title}</span>
                <span className="w-data w-row-end">{formatDate(d.date, locale)}</span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="w-muted">{modules.documentsUnavailable ? t.workshop.empty.documentsUnavailable : t.workshop.empty.documents}</p>
        );
      }
      if (projection.panel === 'parts') {
        const list = buildShoppingList(bundle, locale);
        return list.groups.length > 0 ? (
          <div className="w-groups">
            {list.groups.map((g) => (
              <div key={g.taskId} className="w-group">
                <h3 className="w-group-title">{g.title}</h3>
                <PartRows parts={g.parts} />
              </div>
            ))}
          </div>
        ) : (
          <p className="w-muted">{t.workshop.pieces.none}</p>
        );
      }
      if (projection.panel === 'visits') return <History bundle={bundle} keys={projection.keys} />;
      const keys = new Set(projection.keys);
      const jobs = [...modules.aFaire, ...modules.bientot].filter((j) => keys.size === 0 || keys.has(j.type));
      return jobs.length > 0 ? (
        <ul className="w-rows">
          {jobs.map((j) => (
            <li key={j.id} className="w-row">
              <StateMark state={j.state} immediate={j.immediate} />
              <span className="w-row-main" title={jobTitle(j, locale)}>{jobTitle(j, locale)}</span>
              {!j.immediate && <span className="w-data w-row-end">{jobWhen(j, locale)}</span>}
            </li>
          ))}
        </ul>
      ) : (
        <p className="w-muted">{t.workshop.empty.bientot}</p>
      );
    }

    case 'history':
      return <History bundle={bundle} keys={[]} />;

    case 'parts': {
      const task = ((bundle.vehicle.plannedTasks ?? []) as PlannedTask[]).find((tk) => tk.id === projection.taskId);
      const parts = projection.parts ?? task?.parts ?? [];
      return parts.length > 0 ? <PartRows parts={parts} /> : <p className="w-muted">{t.workshop.projection.noParts}</p>;
    }

    case 'assembly':
      return (
        <div className="w-assembly">
          <p className="w-lede">{t.workshop.car.focus(t.region[projection.region])}</p>
          {projection.partKeys.length > 0 && (
            <>
              <h3 className="w-group-title">{t.workshop.projection.partKeys}</h3>
              <ul className="w-rows">
                {projection.partKeys.map((k) => (
                  <li key={k} className="w-row">
                    <span className="w-row-main" title={serviceLabel(k, locale) || factLabel(k, locale)}>{serviceLabel(k, locale) || factLabel(k, locale)}</span>
                  </li>
                ))}
              </ul>
            </>
          )}
        </div>
      );

    case 'document': {
      const doc = view.document;
      if (!doc) return null;
      return (
        <div className="w-docref">
          <p className="w-data w-row-dim">{doc.filename}</p>
          <p className="w-docref-meta">
            {doc.page != null && <span className="w-data">{t.workshop.projection.page(doc.page)}</span>}
            {doc.highlight && <span>{t.workshop.projection.highlight(doc.highlight)}</span>}
          </p>
          <button type="button" className="w-button is-primary" onClick={() => onOpenDocument(doc)}>
            <Open />
            {t.workshop.projection.open}
          </button>
        </div>
      );
    }

  }
}

function History({ bundle, keys }: { bundle: VehicleBundle; keys: string[] }) {
  const t = useT();
  const [locale] = useLocale();
  const rollup = rollupCarnet(bundle.records, locale, 6, keys);
  if (rollup.count === 0) return <p className="w-muted">{t.workshop.empty.visit}</p>;
  return (
    <>
      <ul className="w-rows">
        {rollup.rows.map((r) => (
          <li key={r.id} className="w-row">
            <span className="w-data w-row-date">{formatDate(r.performedAt, locale)}</span>
            <span className="w-row-main" title={r.items.join(' · ')}>{r.items.join(' · ')}</span>
            {r.usageValue != null && <span className="w-data w-row-dim">{formatReading(r.usageValue, r.usageUnit ?? undefined, locale)}</span>}
            {r.costCents != null && <span className="w-data w-row-end">{formatEuros(r.costCents, locale)}</span>}
          </li>
        ))}
      </ul>
      <p className="w-sum">
        <span>{t.workshop.projection.visits(rollup.count)}</span>
        <span className="w-data">{t.workshop.projection.total(formatEuros(rollup.totalCents, locale))}</span>
      </p>
    </>
  );
}

function UrgentJob({ job, bundle, onPlan, disabled, onView }: { job: JobItem; bundle: VehicleBundle; onPlan?: (job: JobItem) => void; disabled: boolean; onView: (action: ViewAction) => void }) {
  const t = useT();
  const [locale] = useLocale();
  const title = sentenceCase(jobTitle(job, locale));
  const service = serviceLabel(job.type, locale);
  const subtitle = sameLabel(title, service) ? null : service;
  return (
    <div className="w-urgent">
      <h2 className="w-figure-title w-urgent-head">{title}</h2>
      {subtitle && <p className="w-row-dim">{subtitle}</p>}
      <p className="w-urgent-when">
        <StateMark state={job.state} immediate={job.immediate} />
        {job.taskId && <StateMark state="planned" text={t.workshop.urgent.planned} />}
        {!job.immediate && <span className="w-data w-figure-sm">{jobWhen(job, locale)}</span>}
        {job.dueReading !== null && job.remaining && <span className="w-data w-row-dim">{t.workshop.atReading(reading(job.dueReading, job.remaining.unit, locale))}</span>}
        {job.dueDate && <span className="w-data w-row-dim">{formatDate(job.dueDate, locale)}</span>}
      </p>
      {job.parts.length > 0 ? (
        <>
          <h3 className="w-group-title">{t.workshop.urgent.parts}</h3>
          <PartRows parts={job.parts.slice(0, URGENT_PARTS)} itemType={job.type} thumbs tone={job.state === 'over' && !isArchived(bundle.vehicle) ? 'over' : undefined} />
          {job.parts.length > URGENT_PARTS && (
            <button type="button" className="w-more" onClick={() => onView({ type: 'section', section: 'pieces' })}>
              {t.workshop.more.parts(job.parts.length - URGENT_PARTS)}
            </button>
          )}
        </>
      ) : (
        <p className="w-row-dim">{t.workshop.urgent.noParts}</p>
      )}
      <div className="w-actions">
        {onPlan && !job.taskId && (
          <button type="button" className="w-button is-primary" onClick={() => onPlan(job)} disabled={disabled}>
            {t.workshop.urgent.plan}
          </button>
        )}
        {disabled && onPlan && !job.taskId && <span className="w-wait">{t.workshop.waitingAgent}</span>}
        <JobEditActions job={job} bundle={bundle} variant="button" />
      </div>
    </div>
  );
}

interface Card {
  key: number;
  node: ReactNode;
}

/** The one zone that changes when something is summoned: the outgoing card sinks out while the new one rises. */
export function ProjectionSlot(props: SlotProps) {
  const t = useT();
  const [locale] = useLocale();
  const { view } = props;
  const projection = view.projection;
  const urgent = projection ? null : urgentJob(props.modules);
  const current = projection ? (
    <div className="w-proj-card">
      <header className="w-mod-head">
        <h2 className="w-mod-title w-proj-tab">{titleOf(projection, view, t, locale)}</h2>
        <button type="button" className="w-icon-button w-proj-close" onClick={props.onClose} aria-label={t.workshop.projection.close} title={t.workshop.projection.close}>
          <Close />
        </button>
      </header>
      <div className="w-mod-body">
        <Body {...props} projection={projection} />
      </div>
    </div>
  ) : (
    <div className="w-proj-card">
      <div className="w-mod-body">
        {urgent ? (
          <UrgentJob job={urgent} bundle={props.bundle} onPlan={props.onPlan} disabled={props.actionsDisabled} onView={props.onView} />
        ) : (
          <p className="w-muted">{t.workshop.empty.projection}</p>
        )}
      </div>
    </div>
  );

  const key = projection ? view.projectionKey : -1;
  const snapshot = useRef<Card>({ key, node: current });
  const [leaving, setLeaving] = useState<Card[]>([]);
  const timers = useRef<number[]>([]);
  useLayoutEffect(() => {
    const prev = snapshot.current;
    if (prev.key === key) return;
    setLeaving((ls) => [...ls, prev]);
    timers.current.push(window.setTimeout(() => setLeaving((ls) => ls.filter((l) => l !== prev)), EXIT_MS));
  }, [key]);
  useEffect(() => {
    snapshot.current = { key, node: current };
  });
  useEffect(() => () => timers.current.forEach((id) => window.clearTimeout(id)), []);

  return (
    <section className="w-proj" aria-live={props.live ? 'polite' : undefined} aria-label={projection ? titleOf(projection, view, t, locale) : t.workshop.urgent.tab}>
      <div key={key} className={`w-proj-layer${key === -1 ? '' : ' is-entering'}`}>
        {current}
      </div>
      {leaving.map((c) => (
        <div key={`out-${c.key}`} className="w-proj-layer is-leaving" aria-hidden="true">
          {c.node}
        </div>
      ))}
    </section>
  );
}
