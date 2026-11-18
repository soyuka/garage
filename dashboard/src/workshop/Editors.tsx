import { useEffect, useId, useRef, useState, type FormEvent, type KeyboardEvent, type ReactNode } from 'react';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { formatDate } from '../format';
import { serviceLabel, SERVICE_TYPES } from '../labels';
import { actionVerb, formatCadence, ruleTitle } from '../schedule';
import { taskTitle } from '../tasks';
import type { DocumentSpec, Iri, MaintenanceAttachment, MaintenanceRecord, PlannedPart, PlannedTask, ServiceAction, ServiceRule, VehicleBundle, VehicleSummary } from '../types';
import { cadenceUnit, maintUnitFor } from '../units';
import { defaultUsageUnit } from '../edit';
import { isArchived } from './archive';
import { sentenceCase } from './describe';
import {
  acceptsBill,
  BILL_TYPES,
  blankItem,
  blankPart,
  newVehiclePatch,
  ruleDraft,
  ruleInput,
  ruleSeedFor,
  rulesForType,
  taskDraft,
  taskInput,
  taskPartOps,
  VEHICLE_TYPES,
  vehicleDraft,
  vehiclePatch,
  visitDraft,
  visitInput,
  visitUnit,
  type ItemDraft,
  type NewVehicleDraft,
  type PartDraft,
  type RuleDraft,
  type RuleSeed,
  type TaskDraft,
  type TaskPartDraft,
  type VehicleDraft,
  type VisitDraft,
  type VisitSeed,
} from './editForms';
import { PartialSave, useEditHost, type EditHost, type EditorTarget } from './edits';
import { Close } from './Icons';

type T = ReturnType<typeof useT>;
const ACTIONS: ServiceAction[] = ['change', 'replace', 'clean', 'inspect'];

function todayIso(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function messageOf(e: unknown): string {
  return e instanceof Error ? e.message : String(e);
}

/** The service choice: the common catalogue, plus whatever type the record already carries. */
function ServiceSelect({ value, onChange, label, autoFocus }: { value: string; onChange: (v: string) => void; label: string; autoFocus?: boolean }) {
  const t = useT();
  const [locale] = useLocale();
  const types = value && !SERVICE_TYPES.includes(value) ? [value, ...SERVICE_TYPES] : SERVICE_TYPES;
  return (
    <select className="w-input" aria-label={label} value={value} onChange={(e) => onChange(e.target.value)} autoFocus={autoFocus}>
      <option value="">{t.workshop.edit.chooseService}</option>
      {types.map((ty) => (
        <option key={ty} value={ty}>
          {sentenceCase(serviceLabel(ty, locale))}
        </option>
      ))}
    </select>
  );
}

export function Field({ label, children, className = '' }: { label: string; children: ReactNode; className?: string }) {
  return (
    <label className={`w-field ${className}`}>
      <span className="w-field-label">{label}</span>
      {children}
    </label>
  );
}

export function ErrorLines({ errors }: { errors: string[] }) {
  if (errors.length === 0) return null;
  return (
    <div className="w-edit-errors" role="alert">
      {errors.map((e) => (
        <p key={e}>{e}</p>
      ))}
    </div>
  );
}

/** Two steps, no dialog: the button becomes the confirmation and its way out. */
export function ConfirmButton({
  label,
  confirmLabel,
  busyLabel,
  busy,
  disabled,
  onConfirm,
}: {
  label: string;
  confirmLabel: string;
  busyLabel: string;
  busy: boolean;
  disabled?: boolean;
  onConfirm: () => void;
}) {
  const t = useT();
  const [armed, setArmed] = useState(false);
  const confirm = useRef<HTMLButtonElement>(null);
  const first = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    if (armed) confirm.current?.focus();
  }, [armed]);
  const disarm = () => {
    setArmed(false);
    window.requestAnimationFrame(() => first.current?.focus());
  };
  if (busy) {
    return (
      <span className="w-edit-danger">
        <button type="button" className="w-button" disabled>
          {busyLabel}
        </button>
      </span>
    );
  }
  if (!armed) {
    return (
      <span className="w-edit-danger">
        <button ref={first} type="button" className="w-button" disabled={disabled} onClick={() => setArmed(true)}>
          {label}
        </button>
      </span>
    );
  }
  return (
    <span
      className="w-edit-danger is-armed"
      onKeyDown={(e) => {
        if (e.key !== 'Escape') return;
        e.stopPropagation();
        disarm();
      }}
    >
      <button ref={confirm} type="button" className="w-button w-button--confirm" onClick={onConfirm}>
        {confirmLabel}
      </button>
      <button type="button" className="w-button" onClick={disarm}>
        {t.workshop.edit.cancel}
      </button>
    </span>
  );
}

export function EditorFrame({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
  const t = useT();
  const id = useId();
  const onKeyDown = (e: KeyboardEvent<HTMLElement>) => {
    if (e.key !== 'Escape') return;
    e.stopPropagation();
    onClose();
  };
  return (
    <section className="w-editor" aria-labelledby={id} onKeyDown={onKeyDown}>
      <header className="w-mod-head">
        <h2 id={id} className="w-mod-title w-editor-title">
          {title}
        </h2>
        <button type="button" className="w-icon-button w-editor-close" onClick={onClose} aria-label={t.workshop.edit.close} title={t.workshop.edit.close}>
          <Close />
        </button>
      </header>
      {children}
    </section>
  );
}

function SaveRow({ busy, saveLabel, onCancel, children }: { busy: boolean; saveLabel?: string; onCancel: () => void; children?: ReactNode }) {
  const t = useT();
  return (
    <div className="w-actions w-edit-actions">
      <button type="submit" className="w-button is-primary" disabled={busy}>
        {busy ? t.workshop.edit.saving : (saveLabel ?? t.workshop.edit.save)}
      </button>
      <button type="button" className="w-button" onClick={onCancel}>
        {t.workshop.edit.cancel}
      </button>
      {children}
    </div>
  );
}

function PartRowsEditor<P extends PartDraft>({ parts, onChange, make }: { parts: P[]; onChange: (parts: P[]) => void; make: () => P }) {
  const t = useT();
  const f = t.workshop.edit.field;
  const set = (i: number, patch: Partial<PartDraft>) => onChange(parts.map((p, k) => (k === i ? { ...p, ...patch } : p)));
  return (
    <div className="w-edit-parts">
      {parts.length > 0 && (
        <div className="w-edit-part w-edit-part--head" aria-hidden="true">
          <span>{f.ref}</span>
          <span>{f.brand}</span>
          <span>{f.partLabel}</span>
          <span>{f.qty}</span>
          <span>{f.unitPrice}</span>
          <span />
        </div>
      )}
      {parts.map((p, i) => (
        <div key={i} className="w-edit-part">
          <input className="w-input w-input--data" aria-label={f.ref} placeholder={f.ref} value={p.ref} onChange={(e) => set(i, { ref: e.target.value })} />
          <input className="w-input" aria-label={f.brand} placeholder={f.brand} value={p.brand} onChange={(e) => set(i, { brand: e.target.value })} />
          <input className="w-input" aria-label={f.partLabel} placeholder={f.partLabel} value={p.label} onChange={(e) => set(i, { label: e.target.value })} />
          <input className="w-input w-input--data" inputMode="numeric" aria-label={f.qty} placeholder={f.qty} value={p.qty} onChange={(e) => set(i, { qty: e.target.value })} />
          <input className="w-input w-input--data" inputMode="decimal" aria-label={f.unitPrice} placeholder="€" value={p.price} onChange={(e) => set(i, { price: e.target.value })} />
          <button type="button" className="w-icon-button" aria-label={t.workshop.edit.removePart} title={t.workshop.edit.removePart} onClick={() => onChange(parts.filter((_, k) => k !== i))}>
            <Close />
          </button>
        </div>
      ))}
      <button type="button" className="w-more" onClick={() => onChange([...parts, make()])}>
        {t.workshop.edit.addPart}
      </button>
    </div>
  );
}

// ── Visit ───────────────────────────────────────────────────────────────────────

interface BillUpload {
  key: string;
  file: File;
  error: string | null;
}

export function VisitEditor({ host, bundle, recordId, seed = null, onOpenBill }: { host: EditHost; bundle: VehicleBundle; recordId: Iri | null; seed?: VisitSeed | null; onOpenBill: (doc: DocumentSpec) => void }) {
  const t = useT();
  const [locale] = useLocale();
  const e = t.workshop.edit;
  const vehicleIri = bundle.vehicle['@id'];
  const [iri, setIri] = useState<Iri | null>(recordId);
  const record: MaintenanceRecord | null = iri ? (bundle.records.find((r) => r.id === iri) ?? null) : null;
  const unit = visitUnit(record, bundle.vehicle.usageUnit, bundle.vehicle.vehicleType);
  const [draft, setDraft] = useState<VisitDraft>(() => visitDraft(record, todayIso(), seed));
  const [pending, setPending] = useState<File[]>([]);
  const [uploads, setUploads] = useState<BillUpload[]>([]);
  const [attached, setAttached] = useState<MaintenanceAttachment[]>([]);
  const [removed, setRemoved] = useState<string[]>([]);
  const [errors, setErrors] = useState<string[]>([]);
  const [busy, setBusy] = useState<'save' | 'delete' | null>(null);
  const fileInput = useRef<HTMLInputElement>(null);
  const uploadSeq = useRef(0);
  const set = (patch: Partial<VisitDraft>) => setDraft((d) => ({ ...d, ...patch }));
  const setItem = (i: number, patch: Partial<ItemDraft>) => setDraft((d) => ({ ...d, items: d.items.map((it, k) => (k === i ? { ...it, ...patch } : it)) }));
  const logged = record?.attachments ?? [];
  const bills = [...logged, ...attached.filter((a) => !logged.some((l) => l.id === a.id))].filter((a) => !removed.includes(a.url));

  const submit = (ev: FormEvent) => {
    ev.preventDefault();
    const out = visitInput(draft, unit);
    if (!out.ok) {
      setErrors(out.errors.map((k) => e.errors[k] ?? k));
      return;
    }
    setErrors([]);
    setBusy('save');
    const remove = removed.filter((url) => [...logged, ...attached].some((a) => a.url === url));
    host.edits
      .saveVisit(vehicleIri, iri, out.input, { add: pending, remove })
      .then(() => host.close())
      .catch((err) => {
        if (err instanceof PartialSave) setIri(err.iri);
        setErrors([e.failed(messageOf(err))]);
      })
      .finally(() => setBusy(null));
  };
  const remove = () => {
    if (!iri) return;
    setBusy('delete');
    host.edits
      .deleteVisit(vehicleIri, iri)
      .then(() => host.close())
      .catch((err) => setErrors([e.deleteFailed(messageOf(err))]))
      .finally(() => setBusy(null));
  };
  const addFiles = (files: FileList | null) => {
    const list = Array.from(files ?? []);
    const bad = list.filter((f) => !acceptsBill(f.type));
    setErrors(bad.map((f) => e.billRejected(f.name)));
    const good = list.filter((f) => acceptsBill(f.type));
    if (!iri) {
      setPending((p) => [...p, ...good]);
      return;
    }
    for (const file of good) {
      const key = `${file.name}-${++uploadSeq.current}`;
      setUploads((u) => [...u, { key, file, error: null }]);
      host.edits
        .attachBill(vehicleIri, iri, file)
        .then((attachment) => {
          setUploads((u) => u.filter((x) => x.key !== key));
          setAttached((a) => [...a, attachment]);
        })
        .catch((err) => setUploads((u) => u.map((x) => (x.key === key ? { ...x, error: messageOf(err) } : x))));
    }
  };

  const title = record ? e.visitOf(formatDate(record.performedAt, locale)) : e.newVisit;
  return (
    <EditorFrame title={title} onClose={host.close}>
      <form className="w-edit-form" onSubmit={submit} noValidate>
        <div className="w-edit-grid">
          <Field label={e.field.date}>
            <input className="w-input w-input--data" type="date" value={draft.performedAt} onChange={(ev) => set({ performedAt: ev.target.value })} autoFocus required />
          </Field>
          <Field label={e.field.reading(cadenceUnit(unit))}>
            <input className="w-input w-input--data" inputMode="numeric" value={draft.reading} onChange={(ev) => set({ reading: ev.target.value })} />
          </Field>
          <Field label={e.field.label} className="w-field--wide">
            <input className="w-input" value={draft.label} onChange={(ev) => set({ label: ev.target.value })} />
          </Field>
          <label className="w-check">
            <input type="checkbox" checked={draft.diy} onChange={(ev) => set({ diy: ev.target.checked })} />
            <span>{e.field.diy}</span>
          </label>
          {!draft.diy && (
            <Field label={e.field.garage}>
              <input className="w-input" value={draft.performer} onChange={(ev) => set({ performer: ev.target.value })} />
            </Field>
          )}
          <Field label={e.field.cost}>
            <input className="w-input w-input--data" inputMode="decimal" value={draft.cost} onChange={(ev) => set({ cost: ev.target.value })} />
          </Field>
        </div>

        <fieldset className="w-edit-set">
          <legend className="w-group-title">{e.services}</legend>
          {draft.items.map((it, i) => (
            <div key={i} className="w-edit-item">
              <div className="w-edit-item-head">
                <ServiceSelect label={e.field.service} value={it.type} onChange={(type) => setItem(i, { type })} />
                <input className="w-input" aria-label={e.field.detail} placeholder={e.field.detail} value={it.label} onChange={(ev) => setItem(i, { label: ev.target.value })} />
                {draft.items.length > 1 && (
                  <button type="button" className="w-icon-button" aria-label={e.removeItem} title={e.removeItem} onClick={() => set({ items: draft.items.filter((_, k) => k !== i) })}>
                    <Close />
                  </button>
                )}
              </div>
              <PartRowsEditor parts={it.parts} onChange={(parts) => setItem(i, { parts })} make={blankPart} />
            </div>
          ))}
          <button type="button" className="w-more" onClick={() => set({ items: [...draft.items, blankItem()] })}>
            {e.addItem}
          </button>
        </fieldset>

        <Field label={e.field.notes} className="w-field--block">
          <textarea className="w-input w-textarea" rows={2} value={draft.notes} onChange={(ev) => set({ notes: ev.target.value })} />
        </Field>

        <fieldset className="w-edit-set">
          <legend className="w-group-title">{e.bills}</legend>
          {(bills.length > 0 || uploads.length > 0 || pending.length > 0) && (
            <ul className="w-rows">
              {bills.map((a) => (
                <li key={a.id} className="w-row">
                  <button
                    type="button"
                    className="w-row-main w-edit-link"
                    aria-label={e.openBill(a.filename)}
                    onClick={() => onOpenBill({ url: a.url, title: a.filename, contentType: a.contentType, filename: a.filename })}
                  >
                    {a.filename}
                  </button>
                  <button type="button" className="w-icon-button" aria-label={e.removeBill(a.filename)} title={e.removeBill(a.filename)} onClick={() => setRemoved((r) => [...r, a.url])}>
                    <Close />
                  </button>
                </li>
              ))}
              {uploads.map((u) => (
                <li key={u.key} className="w-row">
                  <span className="w-row-main">{u.file.name}</span>
                  {u.error === null ? (
                    <span className="w-row-dim" role="status">
                      {e.billUploading}
                    </span>
                  ) : (
                    <>
                      <span className="w-edit-bill-error" role="alert">
                        {e.billFailed(u.error)}
                      </span>
                      <button type="button" className="w-icon-button" aria-label={e.removeBill(u.file.name)} title={e.removeBill(u.file.name)} onClick={() => setUploads((x) => x.filter((y) => y.key !== u.key))}>
                        <Close />
                      </button>
                    </>
                  )}
                </li>
              ))}
              {pending.map((f, i) => (
                <li key={`${f.name}-${i}`} className="w-row">
                  <span className="w-row-main">{f.name}</span>
                  <span className="w-row-dim">{e.billPending}</span>
                  <button type="button" className="w-icon-button" aria-label={e.removeBill(f.name)} title={e.removeBill(f.name)} onClick={() => setPending((p) => p.filter((_, k) => k !== i))}>
                    <Close />
                  </button>
                </li>
              ))}
            </ul>
          )}
          <button type="button" className="w-more" onClick={() => fileInput.current?.click()}>
            {e.addBill}
          </button>
          <input
            ref={fileInput}
            type="file"
            accept={BILL_TYPES.join(',')}
            multiple
            hidden
            onChange={(ev) => {
              addFiles(ev.target.files);
              ev.target.value = '';
            }}
          />
        </fieldset>

        <ErrorLines errors={errors} />
        <SaveRow busy={busy === 'save'} onCancel={host.close}>
          {iri && <ConfirmButton label={e.deleteVisit} confirmLabel={e.confirmDelete} busyLabel={e.deleting} busy={busy === 'delete'} disabled={busy !== null} onConfirm={remove} />}
        </SaveRow>
      </form>
    </EditorFrame>
  );
}

// ── Service rule ────────────────────────────────────────────────────────────────

export function RuleEditor({ host, bundle, seed }: { host: EditHost; bundle: VehicleBundle; seed: RuleSeed | null }) {
  const t = useT();
  const [locale] = useLocale();
  const e = t.workshop.edit;
  const vehicleIri = bundle.vehicle['@id'];
  const [rules, setRules] = useState<ServiceRule[] | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [selected, setSelected] = useState<string | null>(null);
  useEffect(() => {
    let live = true;
    host.edits
      .loadRules(vehicleIri)
      .then((rs) => live && setRules(rs))
      .catch((err) => live && setLoadError(e.loadFailed(messageOf(err))));
    return () => {
      live = false;
    };
  }, [host.edits, vehicleIri, e]);

  const shown = rules ? (seed ? rulesForType(rules, seed.type) : rules) : [];
  const current = selected ?? shown[0]?.id ?? 'new';
  const rule = current === 'new' ? null : (shown.find((r) => r.id === current) ?? null);
  const title = seed ? e.rules(sentenceCase(serviceLabel(seed.type, locale))) : e.schedule;

  return (
    <EditorFrame title={title} onClose={host.close}>
      {loadError && <ErrorLines errors={[loadError]} />}
      {!rules && !loadError && <p className="w-muted">{e.loading}</p>}
      {(rules || loadError) && (
        <>
          {shown.length > 0 ? (
            <ul className="w-rows w-edit-picks">
              {shown.map((r) => (
                <li key={r.id} className="w-row">
                  <button type="button" className={`w-edit-pick${r.id === current ? ' is-current' : ''}`} aria-pressed={r.id === current} onClick={() => setSelected(r.id)}>
                    <span className="w-row-main">{sentenceCase(ruleTitle(r, locale))}</span>
                    <span className="w-data w-row-dim">{formatCadence(r, locale)}</span>
                  </button>
                </li>
              ))}
            </ul>
          ) : (
            rules && <p className="w-muted">{seed ? e.noRules : e.noSchedule}</p>
          )}
          {shown.length > 0 && current !== 'new' && (
            <button type="button" className="w-more" onClick={() => setSelected('new')}>
              {e.addRule}
            </button>
          )}
          <RuleForm key={current} host={host} bundle={bundle} rule={rule} seed={seed} />
        </>
      )}
    </EditorFrame>
  );
}

function RuleForm({ host, bundle, rule, seed }: { host: EditHost; bundle: VehicleBundle; rule: ServiceRule | null; seed: RuleSeed | null }) {
  const t = useT();
  const [locale] = useLocale();
  const e = t.workshop.edit;
  const vehicleIri = bundle.vehicle['@id'];
  const unit = rule?.everyUsage?.unit ?? maintUnitFor(bundle.vehicle.usageUnit ?? defaultUsageUnit(bundle.vehicle.vehicleType));
  const [draft, setDraft] = useState<RuleDraft>(() => ruleDraft(rule, seed));
  const [errors, setErrors] = useState<string[]>([]);
  const [busy, setBusy] = useState<'save' | 'delete' | null>(null);
  const set = (patch: Partial<RuleDraft>) => setDraft((d) => ({ ...d, ...patch }));
  const submit = (ev: FormEvent) => {
    ev.preventDefault();
    const out = ruleInput(draft, unit);
    if (!out.ok) {
      setErrors(out.errors.map((k) => e.errors[k] ?? k));
      return;
    }
    setErrors([]);
    setBusy('save');
    host.edits
      .saveRule(vehicleIri, out.input, rule)
      .then(() => host.close())
      .catch((err) => setErrors([e.failed(messageOf(err))]))
      .finally(() => setBusy(null));
  };
  const remove = () => {
    if (!rule) return;
    setBusy('delete');
    host.edits
      .deleteRule(vehicleIri, rule.id)
      .then(() => host.close())
      .catch((err) => setErrors([e.deleteFailed(messageOf(err))]))
      .finally(() => setBusy(null));
  };
  const u = cadenceUnit(unit);
  return (
    <form className="w-edit-form" onSubmit={submit} noValidate aria-label={rule ? e.editRule(ruleTitle(rule, locale)) : e.newRule}>
      <div className="w-edit-grid">
        <Field label={e.field.service}>
          <ServiceSelect label={e.field.service} value={draft.type} onChange={(type) => set({ type })} autoFocus />
        </Field>
        <Field label={e.field.action}>
          <select className="w-input" value={draft.action} onChange={(ev) => set({ action: ev.target.value as ServiceAction })}>
            {ACTIONS.map((a) => (
              <option key={a} value={a}>
                {sentenceCase(actionVerb(a, locale))}
              </option>
            ))}
          </select>
        </Field>
        <Field label={e.field.everyUsage(u)}>
          <input className="w-input w-input--data" inputMode="numeric" value={draft.usage} onChange={(ev) => set({ usage: ev.target.value })} />
        </Field>
        <Field label={e.field.everyMonths}>
          <input className="w-input w-input--data" inputMode="numeric" value={draft.months} onChange={(ev) => set({ months: ev.target.value })} />
        </Field>
        <Field label={e.field.firstAt(u)}>
          <input className="w-input w-input--data" inputMode="numeric" value={draft.first} onChange={(ev) => set({ first: ev.target.value })} />
        </Field>
      </div>
      {draft.component && <p className="w-row-dim">{draft.component}</p>}
      <ErrorLines errors={errors} />
      <SaveRow busy={busy === 'save'} onCancel={host.close}>
        {rule && <ConfirmButton label={e.deleteRule} confirmLabel={e.confirmDelete} busyLabel={e.deleting} busy={busy === 'delete'} disabled={busy !== null} onConfirm={remove} />}
      </SaveRow>
    </form>
  );
}

// ── Planned task ────────────────────────────────────────────────────────────────

export function TaskEditor({ host, bundle, taskId }: { host: EditHost; bundle: VehicleBundle; taskId: Iri | null }) {
  const t = useT();
  const [locale] = useLocale();
  const e = t.workshop.edit;
  const vehicleIri = bundle.vehicle['@id'];
  const [iri, setIri] = useState<Iri | null>(taskId);
  const task: PlannedTask | null = iri ? (((bundle.vehicle.plannedTasks ?? []) as PlannedTask[]).find((tk) => tk.id === iri) ?? null) : null;
  const unit = maintUnitFor(bundle.vehicle.usageUnit ?? defaultUsageUnit(bundle.vehicle.vehicleType));
  const [draft, setDraft] = useState<TaskDraft>(() => taskDraft(task));
  const [errors, setErrors] = useState<string[]>([]);
  const [busy, setBusy] = useState<'save' | 'dismiss' | null>(null);
  const set = (patch: Partial<TaskDraft>) => setDraft((d) => ({ ...d, ...patch }));
  const derived = (task?.parts ?? []).some((p) => !p.id);

  const submit = (ev: FormEvent) => {
    ev.preventDefault();
    const fields = taskInput(draft, unit);
    const parts = taskPartOps(task?.parts ?? [], draft.parts);
    const errs = [...(fields.ok ? [] : fields.errors), ...(parts.ok ? [] : parts.errors)];
    if (!fields.ok || !parts.ok) {
      setErrors(errs.map((k) => e.errors[k] ?? k));
      return;
    }
    setErrors([]);
    setBusy('save');
    host.edits
      .saveTask(vehicleIri, iri, fields.fields, parts.ops)
      .then(() => host.close())
      .catch((err) => {
        if (err instanceof PartialSave) setIri(err.iri);
        setErrors([e.failed(messageOf(err))]);
      })
      .finally(() => setBusy(null));
  };
  const dismiss = () => {
    if (!iri) return;
    setBusy('dismiss');
    host.edits
      .dismissTask(vehicleIri, iri)
      .then(() => host.close())
      .catch((err) => setErrors([e.failed(messageOf(err))]))
      .finally(() => setBusy(null));
  };
  const u = cadenceUnit(draft.targetUnit ?? unit);
  return (
    <EditorFrame title={task ? sentenceCase(taskTitle(task, locale)) : e.newTask} onClose={host.close}>
      <form className="w-edit-form" onSubmit={submit} noValidate>
        <div className="w-edit-grid">
          <Field label={e.field.service}>
            <ServiceSelect label={e.field.service} value={draft.type} onChange={(type) => set({ type })} autoFocus />
          </Field>
          <Field label={e.field.label}>
            <input className="w-input" value={draft.label} onChange={(ev) => set({ label: ev.target.value })} />
          </Field>
          <Field label={e.field.target(u)}>
            <input className="w-input w-input--data" inputMode="numeric" value={draft.target} onChange={(ev) => set({ target: ev.target.value })} />
          </Field>
          <Field label={e.field.dueDate}>
            <input className="w-input w-input--data" type="date" value={draft.dueDate} disabled={draft.target.trim() !== ''} onChange={(ev) => set({ dueDate: ev.target.value })} />
          </Field>
        </div>
        <fieldset className="w-edit-set">
          <legend className="w-group-title">{e.parts}</legend>
          <PartRowsEditor<TaskPartDraft> parts={draft.parts} onChange={(parts) => set({ parts })} make={() => ({ id: null, ...blankPart() })} />
          {derived && <p className="w-row-dim">{e.derivedParts}</p>}
        </fieldset>
        <ErrorLines errors={errors} />
        <SaveRow busy={busy === 'save'} onCancel={host.close}>
          {iri && <ConfirmButton label={e.dismissTask} confirmLabel={e.confirmDismiss} busyLabel={e.saving} busy={busy === 'dismiss'} disabled={busy !== null} onConfirm={dismiss} />}
        </SaveRow>
      </form>
    </EditorFrame>
  );
}

/** The column editor for whatever the host has open. */
export function OpenEditor({ target, bundle, onOpenBill }: { target: EditorTarget; bundle: VehicleBundle; onOpenBill: (doc: DocumentSpec) => void }) {
  const host = useEditHost();
  if (!host) return null;
  if (target.kind === 'visit') return <VisitEditor host={host} bundle={bundle} recordId={target.recordId} seed={target.seed ?? null} onOpenBill={onOpenBill} />;
  if (target.kind === 'task') return <TaskEditor host={host} bundle={bundle} taskId={target.taskId} />;
  return <RuleEditor host={host} bundle={bundle} seed={target.type ? { type: target.type, action: target.action, component: target.component } : null} />;
}

// ── Vehicle ─────────────────────────────────────────────────────────────────────

function VehicleTypeSelect({ value, onChange, t }: { value: string; onChange: (v: string) => void; t: T }) {
  const types = (VEHICLE_TYPES as readonly string[]).includes(value) ? VEHICLE_TYPES : [value, ...VEHICLE_TYPES];
  return (
    <select className="w-input" value={value} onChange={(ev) => onChange(ev.target.value)}>
      {types.map((ty) => (
        <option key={ty} value={ty}>
          {t.workshop.edit.vehicleType[ty] ?? ty}
        </option>
      ))}
    </select>
  );
}

/** The identity box of the datasheet, made editable where it stands. */
export function VehicleEditor({ vehicle, onDone }: { vehicle: VehicleSummary; onDone: () => void }) {
  const t = useT();
  const host = useEditHost();
  const e = t.workshop.edit;
  const [draft, setDraft] = useState<VehicleDraft>(() => vehicleDraft(vehicle));
  const [errors, setErrors] = useState<string[]>([]);
  const [busy, setBusy] = useState<'save' | 'delete' | 'archive' | null>(null);
  const set = (patch: Partial<VehicleDraft>) => setDraft((d) => ({ ...d, ...patch }));
  if (!host) return null;
  const unit = cadenceUnit(maintUnitFor(vehicle.usageUnit ?? defaultUsageUnit(draft.vehicleType)));
  const submit = (ev: FormEvent) => {
    ev.preventDefault();
    const out = vehiclePatch(vehicle, draft);
    if (!out.ok) {
      setErrors(out.errors.map((k) => e.errors[k] ?? k));
      return;
    }
    if (Object.keys(out.patch).length === 0) {
      onDone();
      return;
    }
    setErrors([]);
    setBusy('save');
    host.edits
      .saveVehicle(vehicle['@id'], out.patch)
      .then(onDone)
      .catch((err) => setErrors([e.failed(messageOf(err))]))
      .finally(() => setBusy(null));
  };
  const remove = () => {
    setBusy('delete');
    host.edits.deleteVehicle(vehicle['@id']).catch((err) => {
      setErrors([e.deleteFailed(messageOf(err))]);
      setBusy(null);
    });
  };
  const archived = isArchived(vehicle);
  const toggleArchive = () => {
    setErrors([]);
    setBusy('archive');
    host.edits
      .archiveVehicle(vehicle['@id'], !archived)
      .then(onDone)
      .catch((err) => {
        setErrors([(archived ? e.restoreFailed : e.archiveFailed)(messageOf(err))]);
        setBusy(null);
      });
  };
  const f = e.field;
  return (
    <form
      className="w-edit-form w-vehicle-form"
      onSubmit={submit}
      noValidate
      aria-label={e.editVehicle}
      onKeyDown={(ev) => {
        if (ev.key !== 'Escape') return;
        ev.stopPropagation();
        onDone();
      }}
    >
      <div className="w-edit-grid">
        <Field label={f.make}>
          <input className="w-input" value={draft.make} onChange={(ev) => set({ make: ev.target.value })} autoFocus />
        </Field>
        <Field label={f.model}>
          <input className="w-input" value={draft.model} onChange={(ev) => set({ model: ev.target.value })} />
        </Field>
        <Field label={f.plate}>
          <input className="w-input w-input--data" value={draft.plate} onChange={(ev) => set({ plate: ev.target.value })} />
        </Field>
        <Field label={f.usage(unit)}>
          <input className="w-input w-input--data" inputMode="numeric" value={draft.usage} onChange={(ev) => set({ usage: ev.target.value })} />
        </Field>
        <Field label={f.year}>
          <input className="w-input w-input--data" inputMode="numeric" value={draft.year} onChange={(ev) => set({ year: ev.target.value })} />
        </Field>
        <Field label={f.vehicleType}>
          <VehicleTypeSelect value={draft.vehicleType} onChange={(vehicleType) => set({ vehicleType })} t={t} />
        </Field>
        <Field label={f.variant}>
          <input className="w-input" value={draft.engineVariant} onChange={(ev) => set({ engineVariant: ev.target.value })} />
        </Field>
        <Field label={f.engineCode}>
          <input className="w-input w-input--data" value={draft.engineCode} onChange={(ev) => set({ engineCode: ev.target.value })} />
        </Field>
        <Field label={f.kType}>
          <input className="w-input w-input--data" inputMode="numeric" value={draft.kType} onChange={(ev) => set({ kType: ev.target.value })} />
        </Field>
      </div>
      <ErrorLines errors={errors} />
      <SaveRow busy={busy === 'save'} onCancel={onDone}>
        <button type="button" className="w-button" disabled={busy !== null} onClick={toggleArchive}>
          {busy === 'archive' ? (archived ? e.restoring : e.archiving) : archived ? e.restore : e.archive}
        </button>
        <ConfirmButton label={e.deleteVehicle} confirmLabel={e.confirmDelete} busyLabel={e.deleting} busy={busy === 'delete'} disabled={busy !== null} onConfirm={remove} />
      </SaveRow>
    </form>
  );
}

/** The parc's "+ Véhicule": the minimum to name a car, then the car page takes over. */
export function NewVehicleForm({ onCancel }: { onCancel: () => void }) {
  const t = useT();
  const host = useEditHost();
  const e = t.workshop.edit;
  const [draft, setDraft] = useState<NewVehicleDraft>({ make: '', model: '', plate: '', vehicleType: 'car' });
  const [errors, setErrors] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const set = (patch: Partial<NewVehicleDraft>) => setDraft((d) => ({ ...d, ...patch }));
  if (!host) return null;
  const submit = (ev: FormEvent) => {
    ev.preventDefault();
    const out = newVehiclePatch(draft);
    if (!out.ok) {
      setErrors(out.errors.map((k) => e.errors[k] ?? k));
      return;
    }
    setErrors([]);
    setBusy(true);
    host.edits
      .createVehicle(out.patch)
      .catch((err) => setErrors([e.failed(messageOf(err))]))
      .finally(() => setBusy(false));
  };
  const f = e.field;
  return (
    <form
      className="w-edit-form w-new-vehicle"
      onSubmit={submit}
      noValidate
      aria-label={e.newVehicle}
      onKeyDown={(ev) => {
        if (ev.key !== 'Escape') return;
        ev.stopPropagation();
        onCancel();
      }}
    >
      <h2 className="w-mod-title">{e.newVehicle}</h2>
      <div className="w-edit-grid">
        <Field label={f.make}>
          <input className="w-input" value={draft.make} onChange={(ev) => set({ make: ev.target.value })} autoFocus />
        </Field>
        <Field label={f.model}>
          <input className="w-input" value={draft.model} onChange={(ev) => set({ model: ev.target.value })} />
        </Field>
        <Field label={f.plate}>
          <input className="w-input w-input--data" value={draft.plate} onChange={(ev) => set({ plate: ev.target.value })} />
        </Field>
        <Field label={f.vehicleType}>
          <VehicleTypeSelect value={draft.vehicleType} onChange={(vehicleType) => set({ vehicleType })} t={t} />
        </Field>
      </div>
      <ErrorLines errors={errors} />
      <SaveRow busy={busy} saveLabel={e.create} onCancel={onCancel} />
    </form>
  );
}

// ── Entry points ────────────────────────────────────────────────────────────────

/** "Modifier" (a planned task) and "Règle" (its cadence) beside a job, wherever a job is listed. */
export function JobEditActions({ job, bundle }: { job: { type: string; taskId: Iri | null; label: string | null; parts?: PlannedPart[] }; bundle: VehicleBundle }) {
  const t = useT();
  const [locale] = useLocale();
  const host = useEditHost();
  if (!host) return null;
  const e = t.workshop.edit;
  const name = sentenceCase(job.label ?? serviceLabel(job.type, locale));
  const taskId = job.taskId;
  return (
    <span className="w-row-acts">
      {/* Done logs the visit, which closes the matching task server-side; the reading stays the operator's to confirm. */}
      <button
        type="button"
        className="w-act"
        aria-label={e.doneJob(name)}
        onClick={() => host.open({ kind: 'visit', recordId: null, seed: { reading: bundle.vehicle.usageValue ?? null, type: job.type, label: job.label, parts: job.parts ?? [] } })}
      >
        {e.done}
      </button>
      {taskId && (
        <button type="button" className="w-act" aria-label={e.editTask(name)} onClick={() => host.open({ kind: 'task', taskId })}>
          {e.edit}
        </button>
      )}
      <button type="button" className="w-act" aria-label={e.editRule(name)} onClick={() => host.open({ kind: 'rule', ...ruleSeedFor(bundle.forecast.due, job.type) })}>
        {e.rule}
      </button>
    </span>
  );
}

/** "+ Visite / + Tâche / + Règle": the hand-entry line of a section. */
export function AddBar({ items, className = '' }: { items: Array<'visit' | 'task' | 'rule'>; className?: string }) {
  const t = useT();
  const host = useEditHost();
  if (!host) return null;
  const e = t.workshop.edit;
  const label = { visit: e.addVisit, task: e.addTask, rule: e.addRule };
  const target = (k: 'visit' | 'task' | 'rule'): EditorTarget => (k === 'visit' ? { kind: 'visit', recordId: null } : k === 'task' ? { kind: 'task', taskId: null } : { kind: 'rule', type: null });
  return (
    <div className={`w-add-bar ${className}`} role="group" aria-label={e.tools}>
      {items.map((k) => (
        <button key={k} type="button" className="w-more" onClick={() => host.open(target(k))}>
          {label[k]}
        </button>
      ))}
    </div>
  );
}

export function EditVisitButton({ record }: { record: MaintenanceRecord }) {
  const t = useT();
  const [locale] = useLocale();
  const host = useEditHost();
  if (!host) return null;
  const e = t.workshop.edit;
  return (
    <button type="button" className="w-act" aria-label={e.editVisit(e.visitOf(formatDate(record.performedAt, locale)))} onClick={() => host.open({ kind: 'visit', recordId: record.id })}>
      {e.edit}
    </button>
  );
}
