import { useCallback, useEffect, useId, useRef, useState } from 'react';
import './dossier.css';
import { coerceInt } from './edit';
import { formatDate, formatEuros, formatReading } from './format';
import { serviceLabel, SERVICE_TYPES } from './labels';
import { centsToEurosInput, MAINT_USAGE_UNITS, parseEuros, visitTitle } from './records';
import { formatCadence, ruleTitle } from './schedule';
import { safeHref } from './safeHref';
import {
  createMaintenanceRecord,
  deleteMaintenanceAttachment,
  deleteMaintenanceRecord,
  deleteServiceRule,
  fetchAttachmentObjectUrl,
  getServiceSchedule,
  saveServiceRule,
  updateMaintenanceRecord,
  uploadMaintenanceAttachment,
  type MaintenanceRecordInput,
  type ServiceRuleInput,
} from './garage';
import type { MaintenanceAttachment, MaintenanceRecord, ServiceAction, ServiceRule } from './types';

// Le Dossier — the vehicle's deeper file (#11): past maintenance visits (with bills) + the recurring
// service-schedule that drives the forecast. A right-side glass drawer mirroring the journal idiom;
// records ride the bundle (the parent re-reads on change), the schedule is self-fetched on open. NO cyan
// (rationed to Le Noyau). Money is integer minor units on the wire; the forms take euros (the client
// converts). Rebuilt fresh in the Atelier idiom (the old PastMaintenanceRail/ScheduleSection are reference only).

interface DossierProps {
  open: boolean;
  onClose: () => void;
  vehicleIri: string;
  records: MaintenanceRecord[];
  /** Maintenance-side default unit (km|mi|hours) for the visit reading. */
  defaultUnit: string;
  /** Re-read the open vehicle bundle (records + forecast) after a write. */
  onChanged: () => void;
  onError: (message: string) => void;
}

export function DossierPanel({ open, onClose, vehicleIri, records, defaultUnit, onChanged, onError }: DossierProps) {
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) return null;
  return (
    <div className="at-dossier-overlay" onClick={onClose}>
      <aside className="at-dossier glass" onClick={(e) => e.stopPropagation()} aria-label="Dossier du véhicule">
        <header className="at-dossier-head">
          <h2>Dossier</h2>
          <button type="button" className="at-dossier-btn" onClick={onClose} aria-label="Fermer">
            ✕
          </button>
        </header>
        <RecordsSection vehicleIri={vehicleIri} records={records} defaultUnit={defaultUnit} onChanged={onChanged} onError={onError} />
        <ScheduleSection vehicleIri={vehicleIri} onChanged={onChanged} onError={onError} />
      </aside>
    </div>
  );
}

// ── Records ─────────────────────────────────────────────────────────────────

function RecordsSection({
  vehicleIri,
  records,
  defaultUnit,
  onChanged,
  onError,
}: {
  vehicleIri: string;
  records: MaintenanceRecord[];
  defaultUnit: string;
  onChanged: () => void;
  onError: (m: string) => void;
}) {
  const [adding, setAdding] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);

  const submitNew = (input: MaintenanceRecordInput) => {
    createMaintenanceRecord(vehicleIri, input)
      .then(() => {
        setAdding(false);
        onChanged();
      })
      .catch((e) => onError(`Échec de l’enregistrement : ${(e as Error).message}`));
  };
  const submitEdit = (recordIri: string, input: MaintenanceRecordInput) => {
    updateMaintenanceRecord(recordIri, input)
      .then(() => {
        setEditingId(null);
        onChanged();
      })
      .catch((e) => onError(`Échec de la mise à jour : ${(e as Error).message}`));
  };
  const remove = (record: MaintenanceRecord) => {
    if (!window.confirm(`Supprimer l’entretien « ${visitTitle(record)} » du ${formatDate(record.performedAt)} ?`)) return;
    deleteMaintenanceRecord(record.id)
      .then(onChanged)
      .catch((e) => onError(`Échec de la suppression : ${(e as Error).message}`));
  };

  return (
    <section className="at-dossier-section">
      <div className="at-dossier-section-head">
        <h3>Entretiens passés</h3>
        <button type="button" className="at-dossier-btn" aria-expanded={adding} onClick={() => { setAdding((a) => !a); setEditingId(null); }}>
          {adding ? 'Annuler' : '+ Entretien'}
        </button>
      </div>

      {adding && <RecordForm defaultUnit={defaultUnit} onSubmit={submitNew} onCancel={() => setAdding(false)} />}

      {records.length === 0 && !adding ? (
        <p className="at-dossier-empty">Aucun entretien enregistré.</p>
      ) : (
        <ul className="at-dossier-records">
          {records.map((r) =>
            editingId === r.id ? (
              <li key={r.id}>
                <RecordForm record={r} defaultUnit={defaultUnit} onSubmit={(input) => submitEdit(r.id, input)} onCancel={() => setEditingId(null)} />
              </li>
            ) : (
              <li key={r.id}>
                <RecordCard
                  record={r}
                  onEdit={() => { setEditingId(r.id); setAdding(false); }}
                  onDelete={() => remove(r)}
                  onChanged={onChanged}
                  onError={onError}
                />
              </li>
            ),
          )}
        </ul>
      )}
    </section>
  );
}

function RecordCard({
  record,
  onEdit,
  onDelete,
  onChanged,
  onError,
}: {
  record: MaintenanceRecord;
  onEdit: () => void;
  onDelete: () => void;
  onChanged: () => void;
  onError: (m: string) => void;
}) {
  return (
    <div className="at-rec">
      <div className="at-rec-head">
        <span className="at-rec-title">{visitTitle(record)}</span>
        <span className="at-rec-date">{formatDate(record.performedAt)}</span>
        <span className="at-rec-actions">
          <button type="button" className="at-rec-act" aria-label="Modifier l’entretien" title="Modifier" onClick={onEdit}>✎</button>
          <button type="button" className="at-rec-act at-rec-act--del" aria-label="Supprimer l’entretien" title="Supprimer" onClick={onDelete}>✕</button>
        </span>
      </div>
      <div className="at-rec-meta">
        {record.usageValue != null && <span>{formatReading(record.usageValue, record.usageUnit)}</span>}
        {record.costCents != null && <span>{formatEuros(record.costCents)}</span>}
        {record.performedBy && <span>{record.performedBy}</span>}
      </div>
      {record.items.length > 0 && (
        <ul className="at-rec-items">
          {record.items.map((it, i) => (
            <li key={i} className="at-rec-item">
              <span className="at-rec-item-name">{it.label?.trim() || serviceLabel(it.type)}</span>
              {it.parts.length > 0 && (
                <ul className="at-rec-parts">
                  {it.parts.map((p, j) => (
                    <li key={j} className="at-rec-part">
                      {[p.brand, p.ref || p.label].filter(Boolean).join(' ')}
                      {p.qty != null && p.qty > 1 ? ` ×${p.qty}` : ''}
                      {p.priceCents != null ? ` — ${formatEuros(p.priceCents)}` : ''}
                    </li>
                  ))}
                </ul>
              )}
            </li>
          ))}
        </ul>
      )}
      {record.notes && <p className="at-rec-notes">{record.notes}</p>}
      <BillChips record={record} onChanged={onChanged} onError={onError} />
    </div>
  );
}

function BillChips({ record, onChanged, onError }: { record: MaintenanceRecord; onChanged: () => void; onError: (m: string) => void }) {
  const fileInput = useRef<HTMLInputElement>(null);
  const bills = record.attachments ?? [];

  const view = (att: MaintenanceAttachment) => {
    // Owner-gated: fetch with the Bearer → object URL → open; revoke after a grace window (a plain
    // <a href>/<img src> would 404). A non-critical failure is surfaced, never silently swallowed.
    fetchAttachmentObjectUrl(att.url)
      .then((url) => {
        window.open(url, '_blank', 'noopener');
        window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
      })
      .catch((e) => onError(`Échec de l’ouverture de la facture : ${(e as Error).message}`));
  };
  const remove = (att: MaintenanceAttachment) => {
    if (!window.confirm(`Supprimer la facture « ${att.filename} » ?`)) return;
    deleteMaintenanceAttachment(att.url)
      .then(onChanged)
      .catch((e) => onError(`Échec de la suppression : ${(e as Error).message}`));
  };
  const upload = (file: File | undefined) => {
    if (!file) return;
    uploadMaintenanceAttachment(record.id, file)
      .then(onChanged)
      .catch((e) => onError(`Échec de l’ajout de la facture : ${(e as Error).message}`));
  };

  return (
    <div className="at-rec-bills">
      {bills.map((att) => (
        <span className="at-rec-bill" key={att.id}>
          <button type="button" className="at-rec-bill-open" onClick={() => view(att)} title={att.filename}>
            <span aria-hidden="true">{att.contentType.startsWith('image/') ? '🖼️' : '📄'}</span> {att.filename}
          </button>
          <button type="button" className="at-rec-bill-del" aria-label={`Supprimer la facture ${att.filename}`} title="Supprimer" onClick={() => remove(att)}>×</button>
        </span>
      ))}
      <button type="button" className="at-dossier-btn at-dossier-btn--sm" onClick={() => fileInput.current?.click()}>+ facture</button>
      <input
        ref={fileInput}
        type="file"
        accept="application/pdf,image/png,image/jpeg"
        hidden
        onChange={(e) => {
          upload(e.target.files?.[0]);
          e.target.value = ''; // allow re-selecting the same file
        }}
      />
    </div>
  );
}

interface FormPart { ref: string; brand: string; label: string; qty: string; price: string }
interface FormItem { type: string; label: string; parts: FormPart[] }

const blankPart = (): FormPart => ({ ref: '', brand: '', label: '', qty: '', price: '' });
const blankItem = (): FormItem => ({ type: 'oil_change', label: '', parts: [blankPart()] });

function seedItems(record?: MaintenanceRecord): FormItem[] {
  if (!record || record.items.length === 0) return [blankItem()];
  return record.items.map((it) => ({
    type: it.type,
    label: it.label ?? '',
    parts: it.parts.length
      ? it.parts.map((p) => ({ ref: p.ref ?? '', brand: p.brand ?? '', label: p.label ?? '', qty: p.qty != null ? String(p.qty) : '', price: centsToEurosInput(p.priceCents) }))
      : [blankPart()],
  }));
}

function RecordForm({ record, defaultUnit, onSubmit, onCancel }: { record?: MaintenanceRecord; defaultUnit: string; onSubmit: (input: MaintenanceRecordInput) => void; onCancel: () => void }) {
  const listId = useId();
  const [label, setLabel] = useState(record?.label ?? '');
  const [performedAt, setPerformedAt] = useState(record?.performedAt ?? '');
  const [reading, setReading] = useState(record?.usageValue != null ? String(record.usageValue) : '');
  const [unit, setUnit] = useState(record?.usageUnit ?? defaultUnit);
  const [cost, setCost] = useState(centsToEurosInput(record?.costCents));
  const [performer, setPerformer] = useState(record?.performedBy ?? '');
  const [notes, setNotes] = useState(record?.notes ?? '');
  const [items, setItems] = useState<FormItem[]>(() => seedItems(record));

  const setItem = (i: number, patch: Partial<FormItem>) => setItems((arr) => arr.map((it, k) => (k === i ? { ...it, ...patch } : it)));
  const setPart = (i: number, j: number, patch: Partial<FormPart>) =>
    setItems((arr) => arr.map((it, k) => (k === i ? { ...it, parts: it.parts.map((p, m) => (m === j ? { ...p, ...patch } : p)) } : it)));

  const cleanItems = items.filter((it) => it.type.trim());
  const valid = cleanItems.length > 0 && performedAt !== '';

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!valid) return;
    const usageValue = coerceInt(reading);
    const input: MaintenanceRecordInput = {
      performedAt,
      label: label.trim() || null,
      usageValue,
      usageUnit: usageValue != null ? unit : null,
      cost: parseEuros(cost), // euros — the backend converts to costCents
      performedBy: performer.trim() || null,
      notes: notes.trim() || null,
      items: cleanItems.map((it) => ({
        type: it.type.trim(),
        label: it.label.trim() || null,
        parts: it.parts
          .filter((p) => p.ref.trim() || p.label.trim())
          .map((p) => ({ ref: p.ref.trim(), brand: p.brand.trim() || null, label: p.label.trim() || null, qty: coerceInt(p.qty), price: parseEuros(p.price) })),
      })),
    };
    onSubmit(input);
  };

  return (
    <form className="at-recform" onSubmit={submit}>
      <div className="at-recform-row">
        <input className="at-dossier-input at-dossier-input--grow" placeholder="Libellé de la visite (ex. Révision annuelle)" aria-label="Libellé de la visite" value={label} onChange={(e) => setLabel(e.target.value)} />
        <input className="at-dossier-input" type="date" aria-label="Date de l’entretien" value={performedAt} onChange={(e) => setPerformedAt(e.target.value)} required />
      </div>
      <div className="at-recform-row">
        <input className="at-dossier-input at-dossier-input--sm" inputMode="numeric" placeholder="relevé" aria-label="Relevé" value={reading} onChange={(e) => setReading(e.target.value)} />
        <select className="at-dossier-input at-dossier-input--unit" aria-label="Unité du relevé" value={unit} onChange={(e) => setUnit(e.target.value)}>
          {MAINT_USAGE_UNITS.map((u) => (<option key={u} value={u}>{u}</option>))}
        </select>
        <input className="at-dossier-input at-dossier-input--sm" inputMode="decimal" placeholder="coût €" aria-label="Coût en euros" value={cost} onChange={(e) => setCost(e.target.value)} />
        <input className="at-dossier-input at-dossier-input--grow" placeholder="effectué par" aria-label="Effectué par" value={performer} onChange={(e) => setPerformer(e.target.value)} />
      </div>
      <textarea className="at-dossier-input at-recform-notes" placeholder="notes" aria-label="Notes" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />

      <div className="at-recform-items">
        {items.map((it, i) => (
          <div className="at-recform-item" key={i}>
            <div className="at-recform-row">
              <input list={listId} className="at-dossier-input" placeholder="type" aria-label="Type de service" value={it.type} onChange={(e) => setItem(i, { type: e.target.value })} />
              <input className="at-dossier-input at-dossier-input--grow" placeholder="libellé (optionnel)" aria-label="Libellé du service" value={it.label} onChange={(e) => setItem(i, { label: e.target.value })} />
              {items.length > 1 && (
                <button type="button" className="at-recform-x" aria-label="Retirer ce service" title="Retirer" onClick={() => setItems((arr) => arr.filter((_, k) => k !== i))}>×</button>
              )}
            </div>
            {it.parts.map((p, j) => (
              <div className="at-recform-part" key={j}>
                <input className="at-dossier-input at-dossier-input--sm" placeholder="réf." aria-label="Référence" value={p.ref} onChange={(e) => setPart(i, j, { ref: e.target.value })} />
                <input className="at-dossier-input at-dossier-input--sm" placeholder="marque" aria-label="Marque" value={p.brand} onChange={(e) => setPart(i, j, { brand: e.target.value })} />
                <input className="at-dossier-input at-dossier-input--grow" placeholder="libellé" aria-label="Libellé de la pièce" value={p.label} onChange={(e) => setPart(i, j, { label: e.target.value })} />
                <input className="at-dossier-input at-dossier-input--xs" inputMode="numeric" placeholder="qté" aria-label="Quantité" value={p.qty} onChange={(e) => setPart(i, j, { qty: e.target.value })} />
                <input className="at-dossier-input at-dossier-input--xs" inputMode="decimal" placeholder="€" aria-label="Prix unitaire en euros" value={p.price} onChange={(e) => setPart(i, j, { price: e.target.value })} />
                <button type="button" className="at-recform-x" aria-label="Retirer cette pièce" title="Retirer" onClick={() => setItem(i, { parts: it.parts.filter((_, m) => m !== j) })}>×</button>
              </div>
            ))}
            <button type="button" className="at-dossier-btn at-dossier-btn--sm" onClick={() => setItem(i, { parts: [...it.parts, blankPart()] })}>+ pièce</button>
          </div>
        ))}
        <datalist id={listId}>{SERVICE_TYPES.map((t) => (<option key={t} value={t}>{serviceLabel(t)}</option>))}</datalist>
        <button type="button" className="at-dossier-btn at-dossier-btn--sm" onClick={() => setItems((arr) => [...arr, blankItem()])}>+ ajouter un service</button>
      </div>

      <div className="at-recform-actions">
        <button type="submit" className="at-dossier-btn at-dossier-btn--do" disabled={!valid}>{record ? 'Enregistrer' : 'Ajouter'}</button>
        <button type="button" className="at-dossier-btn" onClick={onCancel}>Annuler</button>
      </div>
    </form>
  );
}

// ── Service schedule ──────────────────────────────────────────────────────────

function ScheduleSection({ vehicleIri, onChanged, onError }: { vehicleIri: string; onChanged: () => void; onError: (m: string) => void }) {
  const [rules, setRules] = useState<ServiceRule[]>([]);
  const [loading, setLoading] = useState(true);
  const [adding, setAdding] = useState(false);
  const [editing, setEditing] = useState<ServiceRule | null>(null);

  // onError is a fresh closure on every Atelier render (alarm isn't memoized), so it must NOT be a dep
  // of `load` — else the open-effect would refire on every Mercure frame / Noyau pulse and spam GETs.
  // Read it through a ref; a request token drops a stale/overlapping resolve (out-of-order safety).
  const onErrorRef = useRef(onError);
  onErrorRef.current = onError;
  const reqToken = useRef(0);
  const load = useCallback(() => {
    const token = ++reqToken.current;
    setLoading(true);
    getServiceSchedule(vehicleIri)
      .then((rs) => { if (token === reqToken.current) setRules(rs); })
      .catch((e) => { if (token === reqToken.current) onErrorRef.current(`Échec du chargement du programme : ${(e as Error).message}`); })
      .finally(() => { if (token === reqToken.current) setLoading(false); });
  }, [vehicleIri]);
  useEffect(() => load(), [load]);

  const save = (input: ServiceRuleInput) => {
    saveServiceRule(vehicleIri, input)
      .then(() => {
        setAdding(false);
        setEditing(null);
        load(); // the rule list
        onChanged(); // the forecast it drives
      })
      .catch((e) => onError((e as Error).message)); // saveServiceRule surfaces the 422 RFC-7807 detail
  };
  const remove = (rule: ServiceRule) => {
    deleteServiceRule(vehicleIri, rule.id)
      .then(() => {
        if (editing?.id === rule.id) setEditing(null);
        load();
        onChanged();
      })
      .catch((e) => onError(`Échec de la suppression : ${(e as Error).message}`));
  };

  return (
    <section className="at-dossier-section">
      <div className="at-dossier-section-head">
        <h3>Programme d’entretien</h3>
        <button type="button" className="at-dossier-btn" aria-expanded={adding} onClick={() => { setAdding((a) => !a); setEditing(null); }}>
          {adding ? 'Annuler' : '+ Règle'}
        </button>
      </div>

      {adding && <RuleForm onSave={save} onCancel={() => setAdding(false)} />}

      {loading && rules.length === 0 ? (
        <p className="at-dossier-empty">Chargement…</p>
      ) : rules.length === 0 && !adding ? (
        <p className="at-dossier-empty">Aucune règle d’entretien. Ajoutez-en une pour piloter les prévisions.</p>
      ) : (
        <ul className="at-dossier-rules">
          {rules.map((r) =>
            editing?.id === r.id ? (
              <li key={r.id}><RuleForm rule={r} onSave={save} onCancel={() => setEditing(null)} /></li>
            ) : (
              <li className="at-rule" key={r.id}>
                <button type="button" className="at-rule-main" onClick={() => { setEditing(r); setAdding(false); }} aria-label={`Modifier ${ruleTitle(r)}`}>
                  <span className="at-rule-title">{ruleTitle(r)}</span>
                  <span className="at-rule-cadence">{formatCadence(r)}</span>
                </button>
                <RuleSource rule={r} />
                <button type="button" className="at-rule-del" aria-label={`Retirer ${ruleTitle(r)}`} title="Retirer" onClick={() => remove(r)}>×</button>
              </li>
            ),
          )}
        </ul>
      )}
    </section>
  );
}

function RuleSource({ rule }: { rule: ServiceRule }) {
  const href = safeHref(rule.url);
  const badge = (
    <span className={`at-rule-source${rule.verified ? ' at-rule-source--verified' : ''}`} title={rule.verified ? 'Source vérifiée (catalogue)' : `Source : ${rule.source}`}>
      {rule.verified ? '✓ ' : ''}
      {rule.source}
    </span>
  );
  return href ? <a className="at-rule-link" href={href} target="_blank" rel="noreferrer" title={href}>{badge}</a> : badge;
}

const ACTIONS: ServiceAction[] = ['change', 'replace', 'clean', 'inspect'];

function RuleForm({ rule, onSave, onCancel }: { rule?: ServiceRule; onSave: (input: ServiceRuleInput) => void; onCancel: () => void }) {
  const listId = useId();
  const [type, setType] = useState(rule?.type ?? 'oil_change');
  const [action, setAction] = useState<ServiceAction>(rule?.action ?? 'change');
  const [component, setComponent] = useState(rule?.component ?? '');
  const [usage, setUsage] = useState(rule?.everyUsage?.value != null ? String(rule.everyUsage.value) : '');
  const [usageUnit, setUsageUnit] = useState<string>(rule?.everyUsage?.unit ?? 'km');
  const [months, setMonths] = useState(rule?.everyMonths != null ? String(rule.everyMonths) : '');
  const [first, setFirst] = useState(rule?.firstUsageValue != null ? String(rule.firstUsageValue) : '');

  const usageNum = coerceInt(usage);
  const monthsNum = coerceInt(months);
  const firstNum = coerceInt(first);
  const hasCadence = usageNum != null || monthsNum != null || firstNum != null;
  const valid = type.trim() !== '' && hasCadence;

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!valid) return;
    onSave({
      type: type.trim(),
      action,
      component: component.trim() || null,
      everyUsageValue: usageNum,
      everyUsageUnit: usageNum != null ? (usageUnit as ServiceRuleInput['everyUsageUnit']) : null,
      everyMonths: monthsNum,
      firstUsageValue: firstNum,
    });
  };

  return (
    <form className="at-ruleform" onSubmit={submit}>
      <div className="at-recform-row">
        <input list={listId} className="at-dossier-input" placeholder="type" aria-label="Type de service" value={type} onChange={(e) => setType(e.target.value)} />
        <datalist id={listId}>{SERVICE_TYPES.map((t) => (<option key={t} value={t}>{serviceLabel(t)}</option>))}</datalist>
        <select className="at-dossier-input at-dossier-input--unit" aria-label="Action" value={action} onChange={(e) => setAction(e.target.value as ServiceAction)}>
          {ACTIONS.map((a) => (<option key={a} value={a}>{a}</option>))}
        </select>
        <input className="at-dossier-input at-dossier-input--grow" placeholder="composant (optionnel)" aria-label="Composant" value={component} onChange={(e) => setComponent(e.target.value)} />
      </div>
      <div className="at-recform-row">
        <input className="at-dossier-input at-dossier-input--sm" inputMode="numeric" placeholder="tous les …" aria-label="Cadence kilométrage" value={usage} onChange={(e) => setUsage(e.target.value)} />
        <select className="at-dossier-input at-dossier-input--unit" aria-label="Unité de cadence" value={usageUnit} onChange={(e) => setUsageUnit(e.target.value)}>
          {MAINT_USAGE_UNITS.map((u) => (<option key={u} value={u}>{u}</option>))}
        </select>
        <input className="at-dossier-input at-dossier-input--sm" inputMode="numeric" placeholder="tous les … mois" aria-label="Cadence mois" value={months} onChange={(e) => setMonths(e.target.value)} />
        <input className="at-dossier-input at-dossier-input--sm" inputMode="numeric" placeholder="1ʳᵉ fois à …" aria-label="Premier intervalle" value={first} onChange={(e) => setFirst(e.target.value)} />
      </div>
      <div className="at-recform-actions">
        <button type="submit" className="at-dossier-btn at-dossier-btn--do" disabled={!valid} title={!hasCadence ? 'Indiquez au moins une cadence' : undefined}>
          {rule ? 'Corriger' : 'Ajouter'}
        </button>
        <button type="button" className="at-dossier-btn" onClick={onCancel}>Annuler</button>
      </div>
    </form>
  );
}
