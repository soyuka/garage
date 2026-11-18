import { Suspense, useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { Canvas } from '@react-three/fiber';
import { PerspectiveCamera, View } from '@react-three/drei';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { serviceLabel } from '../labels';
import { MeshErrorBoundary } from '../scene/VehicleBody';
import { useReducedMotion } from '../scene/useReducedMotion';
import type { Iri, VehicleBundle, VehicleSummary } from '../types';
import { carEdgeAngle, FitCamera, LENGTH, vehicleLines } from './CarStage';
import { ConfirmButton, NewVehicleForm } from './Editors';
import { useEditHost } from './edits';
import { jobTitle, jobWhen, reading, sentenceCase } from './describe';
import { staggerDelay } from './edgeDraw';
import { isArchived, splitArchived } from './archive';
import { EdgeModel, useTokenColor, type EdgeFit } from './EdgeModel';
import { isGenericBody, parcThumbUrl, vehicleMesh } from './genericBody';
import { normalizeUnit } from './kmTrack';
import { Module } from './Module';
import { parcDues, rankParc, vehicleNextJob } from './modules';
import type { BodyBox } from './regionPoint';
import { StateMark } from './StateMark';
import { vehicleName } from './TopBar';

const THUMB_FIT: EdgeFit = { mode: 'floor', size: LENGTH, alignLength: true };
const DUES_LIMIT = 8;
// About 11% of the render box clear on every side (at least the 8% floor), whatever the row's aspect:
// 1 / (1 − 2 × 0.115).
const THUMB_MARGIN = 1.3;

function Thumb({ vehicle, index, aspect }: { vehicle: VehicleSummary; index: number; aspect?: number }) {
  const spec = useMemo(() => vehicleMesh(vehicle), [vehicle.make, vehicle.model, vehicle.vehicleType]);
  const url = parcThumbUrl(vehicle);
  const reduced = useReducedMotion();
  const ink = useTokenColor('--w-ink');
  const [box, setBox] = useState<BodyBox | null>(null);
  const [points, setPoints] = useState<Float32Array | null>(null);
  return (
    <View className="w-row-thumb">
      <PerspectiveCamera makeDefault fov={24} near={0.1} far={60} />
      <FitCamera box={box} points={points} aspect={aspect} margin={THUMB_MARGIN} />
      <MeshErrorBoundary key={url} fallback={null}>
        <Suspense fallback={null}>
          <EdgeModel
            url={url}
            angle={carEdgeAngle(url)}
            rotationY={spec.rotationY}
            fit={THUMB_FIT}
            ink={ink}
            delay={staggerDelay(index)}
            reduced={reduced}
            onBox={setBox}
            onPoints={setPoints}
            {...vehicleLines(vehicle.vehicleType)}
          />
        </Suspense>
      </MeshErrorBoundary>
    </View>
  );
}

function DeleteVehicle({ vehicle, name }: { vehicle: VehicleSummary; name: string }) {
  const t = useT();
  const host = useEditHost();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  if (!host) return null;
  const remove = () => {
    setError(null);
    setBusy(true);
    host.edits.deleteVehicle(vehicle['@id']).catch((err) => {
      setError(t.workshop.edit.deleteFailed(err instanceof Error ? err.message : String(err)));
      setBusy(false);
    });
  };
  return (
    <div className="w-vrow-actions" role="group" aria-label={name}>
      {error && (
        <p className="w-vrow-error" role="alert">
          {error}
        </p>
      )}
      <ConfirmButton label={t.workshop.edit.deleteVehicle} confirmLabel={t.workshop.edit.confirmDelete} busyLabel={t.workshop.edit.deleting} busy={busy} onConfirm={remove} />
    </div>
  );
}

function VehicleRow({ vehicle, bundle, now, href, index }: { vehicle: VehicleSummary; bundle: VehicleBundle | undefined; now: Date; href: string; index: number }) {
  const archived = isArchived(vehicle);
  const t = useT();
  const [locale] = useLocale();
  const job = vehicleNextJob(vehicle, bundle, now);
  const name = vehicleName(vehicle);
  const label = job ? sentenceCase(job.label === job.type ? serviceLabel(job.type, locale) : job.label) : null;
  const thumb = useRef<HTMLSpanElement>(null);
  const [aspect, setAspect] = useState<number | undefined>(undefined);
  useEffect(() => {
    const el = thumb.current;
    if (!el) return;
    const ro = new ResizeObserver(([entry]) => setAspect(entry.contentRect.width / Math.max(1, entry.contentRect.height)));
    ro.observe(el);
    return () => ro.disconnect();
  }, []);
  return (
    <div className="w-vrow-item">
      <a className={`w-vrow${archived ? ' is-archived' : ''}`} href={href} aria-label={t.workshop.home.open(name)} data-parc-card>
        <span className="w-vrow-thumb" ref={thumb}>
          <Thumb vehicle={vehicle} index={index} aspect={aspect} />
        </span>
        <span className="w-vrow-name">
          <span className="w-vrow-title">{name}</span>
          {vehicle.plate && <span className="w-data w-muted">{vehicle.plate}</span>}
          {isGenericBody(vehicle) && <span className="w-generic">{t.workshop.genericBody}</span>}
        </span>
        <span className="w-vrow-job">
          {archived ? (
            <span className="w-muted">{t.workshop.home.archivedMark}</span>
          ) : job && label ? (
            <>
              <StateMark state={job.state} immediate={job.immediate} />
              <span className="w-vrow-job-title" title={label}>
                {label}
              </span>
              {!job.immediate && <span className="w-data">{jobWhen({ state: job.state, immediate: false, remaining: job.remaining, days: job.days }, locale)}</span>}
            </>
          ) : (
            <>
              <StateMark state="unknown" />
              <span className="w-muted">{t.workshop.home.noJob}</span>
            </>
          )}
        </span>
        <span className="w-data w-vrow-reading">{vehicle.usageValue != null ? reading(vehicle.usageValue, normalizeUnit(vehicle.usageUnit), locale) : '—'}</span>
      </a>
      <DeleteVehicle vehicle={vehicle} name={name} />
    </div>
  );
}

export function ParcMosaic({
  vehicles,
  bundles,
  now,
  loading,
  error,
  hrefFor,
}: {
  vehicles: VehicleSummary[];
  bundles: Record<string, VehicleBundle>;
  now: Date;
  loading: boolean;
  error: string | null;
  hrefFor: (iri: string) => string;
}) {
  const t = useT();
  const [locale] = useLocale();
  const list = useRef<HTMLDivElement>(null);
  const host = useEditHost();
  const [adding, setAdding] = useState(false);
  const addButton = useRef<HTMLButtonElement>(null);
  const stopAdding = () => {
    setAdding(false);
    window.requestAnimationFrame(() => addButton.current?.focus());
  };
  const [showArchived, setShowArchived] = useState(false);
  const { active, archived } = useMemo(() => splitArchived(vehicles), [vehicles]);
  const ranked = rankParc(active, bundles, now);
  const names = new Map<Iri, string>(active.map((v) => [v['@id'], vehicleName(v)]));
  const loaded = ranked.map((v) => bundles[v['@id']]).filter((b): b is VehicleBundle => !!b);
  const dues = parcDues(loaded, now, { excludeHeadline: true });

  const onKey = (e: KeyboardEvent<HTMLDivElement>) => {
    if (e.key !== 'j' && e.key !== 'k') return;
    const rows = Array.from(list.current?.querySelectorAll<HTMLAnchorElement>('[data-parc-card]') ?? []);
    if (rows.length === 0) return;
    const i = rows.indexOf(document.activeElement as HTMLAnchorElement);
    const next = e.key === 'j' ? Math.min(rows.length - 1, i + 1) : Math.max(0, i < 0 ? 0 : i - 1);
    rows[next].focus();
    e.preventDefault();
  };

  return (
    <main className="w-home">
      <header className="w-home-head">
        <h1 className="w-home-title">{t.workshop.home.title}</h1>
        <span className="w-data w-muted">{t.workshop.home.count(active.length)}</span>
        {host && !adding && (
          <button ref={addButton} type="button" className="w-button w-home-add" onClick={() => setAdding(true)}>
            {t.workshop.edit.addVehicle}
          </button>
        )}
      </header>
      {adding && <NewVehicleForm onCancel={stopAdding} />}
      {error ? (
        <p className="w-muted w-home-empty">{t.atelier.loadFailed(error)}</p>
      ) : loading ? (
        <p className="w-muted w-home-empty">{t.atelier.loadingParc}</p>
      ) : vehicles.length === 0 ? (
        <p className="w-muted w-home-empty">{t.workshop.home.empty}</p>
      ) : (
        <>
          <div className="w-vrows" ref={list} onKeyDown={onKey}>
            {ranked.map((v, i) => (
              <VehicleRow key={v['@id']} vehicle={v} bundle={bundles[v['@id']]} now={now} href={hrefFor(v['@id'])} index={i} />
            ))}
          </div>
          {archived.length > 0 && (
            <section className="w-archived">
              <button type="button" className="w-archived-toggle" aria-expanded={showArchived} aria-controls="w-archived-list" onClick={() => setShowArchived((s) => !s)}>
                {t.workshop.home.archivedSection(archived.length)}
              </button>
              {showArchived && (
                <div className="w-vrows" id="w-archived-list">
                  {archived.map((v, i) => (
                    <VehicleRow key={v['@id']} vehicle={v} bundle={bundles[v['@id']]} now={now} href={hrefFor(v['@id'])} index={ranked.length + i} />
                  ))}
                </div>
              )}
            </section>
          )}
          {dues.length > 0 && (
            <Module title={t.workshop.parcDues} className="w-home-dues" labelledBy="w-home-dues">
              <ul className="w-rows">
                {dues.slice(0, DUES_LIMIT).map(({ vehicleIri, job }) => (
                  <li key={`${vehicleIri}:${job.id}`} className="w-row">
                    <StateMark state={job.state} immediate={job.immediate} />
                    <a className="w-row-main w-due-link" href={hrefFor(vehicleIri)} title={`${jobTitle(job, locale)} · ${names.get(vehicleIri) ?? ''}`}>
                      {sentenceCase(jobTitle(job, locale))}
                    </a>
                    <span className="w-row-dim w-due-vehicle">{names.get(vehicleIri)}</span>
                    {!job.immediate && <span className="w-data w-row-end">{jobWhen(job, locale)}</span>}
                  </li>
                ))}
              </ul>
              {dues.length > DUES_LIMIT && <p className="w-muted w-more-note">{t.workshop.more.rows(dues.length - DUES_LIMIT)}</p>}
            </Module>
          )}
          <Canvas className="w-thumbs-canvas" frameloop="always" dpr={[1, 1.5]} gl={{ antialias: true, alpha: true }} style={{ position: 'fixed', inset: 0, pointerEvents: 'none' }}>
            <View.Port />
          </Canvas>
        </>
      )}
    </main>
  );
}
