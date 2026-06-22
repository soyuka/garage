import { useEffect, useRef, useState } from 'react';
import { fetchAttachmentObjectUrl, type MaintenanceItemInput, type MaintenanceRecordInput, type PartInput } from '../garage';
import { serviceLabel, SERVICE_TYPES } from '../labels';
import type { MaintenanceAttachment, MaintenanceRecord, PlannedPart } from '../types';

/** Browser-accepted bill types — must mirror the server's allow-list (PDF/JPEG/PNG). */
const ACCEPTED_BILL_TYPES = 'application/pdf,image/png,image/jpeg';

/** Format a stored reading (138000 → "138 000 km"); empty when none. */
function reading(value?: number | null, unit?: string | null): string {
  if (value == null) return '';
  return `${value.toLocaleString('fr-FR')}${unit ? ` ${unit}` : ''}`;
}

/** Format a visit bill (18900 cents → "189,00 €"); empty when none. */
function money(cents?: number | null, currency?: string | null): string {
  if (cents == null) return '';
  return (cents / 100).toLocaleString('fr-FR', { style: 'currency', currency: currency ?? 'EUR' });
}

/** A part rendered for the visit checklist: "MANN W712/52 ×2 — 12,50 €" (qty/price shown when set). */
export function partLabel(p: PlannedPart, currency?: string | null): string {
  const name = [p.brand, p.label || p.ref].filter(Boolean).join(' ');
  const withQty = p.qty && p.qty > 1 ? `${name} ×${p.qty}` : name;
  return p.priceCents != null ? `${withQty} — ${money(p.priceCents, currency)}` : withQty;
}

/**
 * The visit's headline. Prefer the human visit label ("Entretien complet"); otherwise the single
 * service's name, falling back to a generic "Entretien" for a multi-item visit with no label.
 */
function visitTitle(r: MaintenanceRecord): string {
  const items = r.items ?? [];
  if (r.label?.trim()) return r.label.trim();
  if (items.length === 1) return items[0].label?.trim() || serviceLabel(items[0].type);
  return items.length ? 'Entretien' : '—';
}

/**
 * Past maintenance as a full-width vertical list of visit cards (newest first). Each card is fully
 * manageable: edit reopens the visit in the form prefilled, delete removes it. A disclosure reveals
 * the same form blank to log a new visit by hand.
 */
export function PastMaintenanceRail({
  records,
  loading = false,
  onAddRecord,
  onEditRecord,
  onDeleteRecord,
  onUploadAttachment,
  onDeleteAttachment,
  prefillType,
  onPrefillConsumed,
}: {
  records: MaintenanceRecord[] | undefined;
  loading?: boolean;
  onAddRecord: (input: MaintenanceRecordInput) => void;
  onEditRecord: (recordIri: string, input: MaintenanceRecordInput) => void;
  onDeleteRecord: (recordIri: string) => void;
  onUploadAttachment: (recordIri: string, file: File) => void;
  onDeleteAttachment: (attachmentUrl: string) => void;
  // A forecast "gap" CTA asks to log a baseline for this service type — open the add form prefilled.
  prefillType?: string | null;
  onPrefillConsumed?: () => void;
}) {
  const [adding, setAdding] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  // The service type a gap CTA seeded the blank add form with; reset once the form is dismissed.
  const [seedType, setSeedType] = useState<string | null>(null);
  const list = records ?? [];
  const editing = editingId ? list.find((r) => r.id === editingId) : undefined;

  // A gap CTA (from the forecast) opens the add form prefilled with its service type; ack it so the
  // parent's transient prefill clears (a second click on the same gap re-opens the form).
  useEffect(() => {
    if (!prefillType) return;
    setEditingId(null);
    setSeedType(prefillType);
    setAdding(true);
    onPrefillConsumed?.();
  }, [prefillType, onPrefillConsumed]);

  return (
    <div className="hist">
      <div className="panel-title">
        <span className="t">Entretiens passés</span>
        <button
          type="button"
          className="task-clean"
          aria-expanded={adding}
          onClick={() => {
            setEditingId(null);
            setSeedType(null);
            setAdding((a) => !a);
          }}
        >
          {adding ? 'Annuler' : '+ Ajouter un entretien'}
        </button>
      </div>

      {editing ? (
        <RecordForm
          key={editing.id}
          initial={editing}
          submitLabel="Mettre à jour"
          onSubmit={(input) => {
            onEditRecord(editing.id, input);
            setEditingId(null);
          }}
          onCancel={() => setEditingId(null)}
        />
      ) : adding ? (
        <RecordForm
          key={seedType ?? 'blank'}
          initialType={seedType ?? undefined}
          submitLabel="Enregistrer"
          onSubmit={(input) => {
            onAddRecord(input);
            setAdding(false);
            setSeedType(null);
          }}
          onCancel={() => {
            setAdding(false);
            setSeedType(null);
          }}
        />
      ) : null}

      {loading ? (
        <div className="hist-track">
          {[0, 1, 2].map((i) => (
            <div key={i} className="hist-card" aria-hidden="true">
              <span className="skel" style={{ width: '60%', height: 13 }} />
              <span className="skel" style={{ width: '40%', height: 11, marginTop: 8 }} />
            </div>
          ))}
        </div>
      ) : list.length === 0 ? (
        <div className="hist-empty">Aucun entretien enregistré.</div>
      ) : (
        <div className="hist-track">
          {list.map((r) => {
            const items = r.items ?? [];
            const title = visitTitle(r);
            // Skip the redundant checklist when it would just echo the title (single unnamed service, no parts).
            const showItems = items.length > 1 || (items.length === 1 && items[0].parts.length > 0);
            return (
              <div key={r.id} className={`hist-card${editingId === r.id ? ' hist-card-active' : ''}`}>
                <div className="hist-card-head">
                  <span className="hist-type">{title}</span>
                  <span className="hist-date">{r.performedAt}</span>
                  <div className="hist-actions">
                    <button
                      type="button"
                      className="hist-act"
                      title="Modifier"
                      aria-label="Modifier l’entretien"
                      onClick={() => {
                        setAdding(false);
                        setEditingId(r.id);
                      }}
                    >
                      ✎
                    </button>
                    <button
                      type="button"
                      className="hist-act hist-act-danger"
                      title="Supprimer"
                      aria-label="Supprimer l’entretien"
                      onClick={() => {
                        if (window.confirm(`Supprimer l’entretien « ${title} » du ${r.performedAt} ?`)) onDeleteRecord(r.id);
                      }}
                    >
                      ✕
                    </button>
                  </div>
                </div>
                {showItems ? (
                  <ul className="hist-items">
                    {items.map((it, i) => (
                      <li key={i} className="hist-item">
                        <span className="hist-item-name">{it.label?.trim() || serviceLabel(it.type)}</span>
                        {it.parts.length ? (
                          <span className="hist-item-parts">{it.parts.map((p) => partLabel(p, r.currency)).join(' · ')}</span>
                        ) : null}
                      </li>
                    ))}
                  </ul>
                ) : null}
                <div className="hist-meta">
                  {reading(r.usageValue, r.usageUnit) ? <span>{reading(r.usageValue, r.usageUnit)}</span> : null}
                  {money(r.costCents, r.currency) ? <span className="hist-cost">{money(r.costCents, r.currency)}</span> : null}
                  {r.performedBy ? <span>{r.performedBy}</span> : null}
                </div>
                {r.notes ? <div className="hist-notes">{r.notes}</div> : null}
                <BillStrip
                  record={r}
                  onUpload={(file) => onUploadAttachment(r.id, file)}
                  onDelete={onDeleteAttachment}
                />
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}

/**
 * The bills attached to one logged visit: a chip per file (click to view it in a new tab), a delete
 * on each, and a button to add more. Bills upload onto the already-saved visit, so this lives on the
 * card rather than in the create form. The download is owner-gated, so viewing fetches the bytes
 * with the Bearer and opens an object URL — a bare link would 404.
 */
function BillStrip({
  record,
  onUpload,
  onDelete,
}: {
  record: MaintenanceRecord;
  onUpload: (file: File) => void;
  onDelete: (attachmentUrl: string) => void;
}) {
  const fileInput = useRef<HTMLInputElement>(null);
  const attachments = record.attachments ?? [];

  const view = async (att: MaintenanceAttachment) => {
    try {
      const url = await fetchAttachmentObjectUrl(att.url);
      window.open(url, '_blank', 'noopener');
      // Give the new tab time to load the blob before releasing it.
      setTimeout(() => URL.revokeObjectURL(url), 60_000);
    } catch {
      /* non-critical: the bill just won't open; the fetch failure is surfaced elsewhere */
    }
  };

  const onPick = (e: React.ChangeEvent<HTMLInputElement>) => {
    Array.from(e.target.files ?? []).forEach(onUpload);
    e.target.value = ''; // let the same file be re-picked after a failed upload
  };

  return (
    <div className="hist-bills">
      {attachments.map((att) => (
        <span key={att.id} className="hist-bill">
          <button type="button" className="hist-bill-name" title={att.filename} onClick={() => view(att)}>
            <span aria-hidden="true">{att.contentType === 'application/pdf' ? '📄' : '🖼️'}</span> {att.filename}
          </button>
          <button
            type="button"
            className="hist-bill-del"
            aria-label={`Supprimer la facture ${att.filename}`}
            onClick={() => {
              if (window.confirm(`Supprimer la facture « ${att.filename} » ?`)) onDelete(att.url);
            }}
          >
            ✕
          </button>
        </span>
      ))}
      <button type="button" className="hist-bill-add" onClick={() => fileInput.current?.click()}>
        + facture
      </button>
      <input ref={fileInput} type="file" accept={ACCEPTED_BILL_TYPES} multiple hidden onChange={onPick} />
    </div>
  );
}

/**
 * Editable part row state. `price` is kept as a raw euros string (not priceCents) so decimal typing
 * ("12,5") isn't mangled mid-keystroke — it's parsed to a number only on submit, like the visit cost.
 */
interface PartDraft {
  ref?: string;
  brand?: string;
  label?: string;
  qty?: number;
  price: string;
}

/** Editable item row state — parts are edited as parallel string fields, parsed on submit. */
interface ItemDraft {
  type: string;
  label: string;
  parts: PartDraft[];
}

function itemDraftsFrom(record?: MaintenanceRecord, initialType?: string): ItemDraft[] {
  if (!record || (record.items ?? []).length === 0) return [{ type: initialType ?? 'oil_change', label: '', parts: [] }];
  return record.items.map((it) => ({
    type: it.type,
    label: it.label ?? '',
    parts: it.parts.map((p) => ({ ref: p.ref, brand: p.brand, label: p.label, qty: p.qty, price: p.priceCents != null ? String(p.priceCents / 100) : '' })),
  }));
}

/**
 * The manual visit form — create (blank) or edit (prefilled from a record). One row per service,
 * each with its own parts; plus the visit-level fields (label, date, reading, cost, who, notes).
 */
function RecordForm({
  initial,
  initialType,
  submitLabel,
  onSubmit,
  onCancel,
}: {
  initial?: MaintenanceRecord;
  // Seed a blank form's first service row with this type (a forecast-gap baseline log).
  initialType?: string;
  submitLabel: string;
  onSubmit: (input: MaintenanceRecordInput) => void;
  onCancel: () => void;
}) {
  const [items, setItems] = useState<ItemDraft[]>(() => itemDraftsFrom(initial, initialType));
  const [label, setLabel] = useState(initial?.label ?? '');
  const [performedAt, setPerformedAt] = useState(initial?.performedAt ?? '');
  const [usageValue, setUsageValue] = useState(initial?.usageValue != null ? String(initial.usageValue) : '');
  const [usageUnit, setUsageUnit] = useState(initial?.usageUnit ?? 'km');
  const [cost, setCost] = useState(initial?.costCents != null ? String(initial.costCents / 100) : '');
  const [performedBy, setPerformedBy] = useState(initial?.performedBy ?? '');
  const [notes, setNotes] = useState(initial?.notes ?? '');

  const setItem = (idx: number, patch: Partial<ItemDraft>) =>
    setItems((prev) => prev.map((it, i) => (i === idx ? { ...it, ...patch } : it)));
  const addItem = () => setItems((prev) => [...prev, { type: '', label: '', parts: [] }]);
  const removeItem = (idx: number) => setItems((prev) => prev.filter((_, i) => i !== idx));

  const setPart = (itemIdx: number, partIdx: number, patch: Partial<PartDraft>) =>
    setItem(itemIdx, { parts: items[itemIdx].parts.map((p, i) => (i === partIdx ? { ...p, ...patch } : p)) });
  const addPart = (itemIdx: number) => setItem(itemIdx, { parts: [...items[itemIdx].parts, { ref: '', price: '' }] });
  const removePart = (itemIdx: number, partIdx: number) =>
    setItem(itemIdx, { parts: items[itemIdx].parts.filter((_, i) => i !== partIdx) });

  const cleanItems: MaintenanceItemInput[] = items
    .map((it) => ({
      type: it.type.trim(),
      label: it.label.trim() || null,
      parts: it.parts
        .map((p): PartInput => {
          const price = Number.parseFloat((p.price ?? '').replace(',', '.'));
          return {
            ref: (p.ref ?? '').trim(),
            brand: p.brand?.trim() || undefined,
            label: p.label?.trim() || undefined,
            qty: p.qty && p.qty > 0 ? p.qty : undefined,
            price: Number.isNaN(price) ? undefined : price,
          };
        })
        .filter((p) => p.ref !== ''),
    }))
    .filter((it) => it.type !== '');

  const valid = cleanItems.length > 0 && performedAt !== '';

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!valid) return;
    const mileage = Number.parseInt(usageValue.replace(/[^\d]/g, ''), 10);
    const price = Number.parseFloat(cost.replace(',', '.'));
    onSubmit({
      items: cleanItems,
      performedAt,
      label: label.trim() || null,
      usageValue: Number.isNaN(mileage) ? null : mileage,
      usageUnit: Number.isNaN(mileage) ? null : usageUnit,
      cost: Number.isNaN(price) ? null : price,
      performedBy: performedBy.trim() || null,
      notes: notes.trim() || null,
    });
  };

  return (
    <form className="record-form" onSubmit={submit}>
      <input
        className="add-input add-input-grow"
        placeholder="Libellé de la visite (ex. Révision annuelle)"
        value={label}
        onChange={(e) => setLabel(e.target.value)}
      />

      <datalist id="service-types">
        {SERVICE_TYPES.map((t) => (
          <option key={t} value={t}>
            {serviceLabel(t)}
          </option>
        ))}
      </datalist>

      <div className="rf-items">
        {items.map((it, i) => (
          <div className="rf-item" key={i}>
            <div className="rf-item-head">
              <input
                list="service-types"
                className="add-input add-input-grow"
                placeholder="type (ex. oil_change) ou texte libre"
                value={it.type}
                onChange={(e) => setItem(i, { type: e.target.value })}
              />
              <input
                className="add-input"
                placeholder="libellé (optionnel)"
                value={it.label}
                onChange={(e) => setItem(i, { label: e.target.value })}
              />
              {items.length > 1 ? (
                <button type="button" className="rf-remove" aria-label="Retirer ce service" onClick={() => removeItem(i)}>
                  ✕
                </button>
              ) : null}
            </div>

            {it.parts.map((p, j) => (
              <div className="rf-part" key={j}>
                <input className="add-input add-input-sm" placeholder="réf." value={p.ref ?? ''} onChange={(e) => setPart(i, j, { ref: e.target.value })} />
                <input className="add-input add-input-sm" placeholder="marque" value={p.brand ?? ''} onChange={(e) => setPart(i, j, { brand: e.target.value })} />
                <input className="add-input add-input-grow" placeholder="libellé pièce" value={p.label ?? ''} onChange={(e) => setPart(i, j, { label: e.target.value })} />
                <input
                  className="add-input add-input-xs"
                  inputMode="numeric"
                  placeholder="qté"
                  value={p.qty != null ? String(p.qty) : ''}
                  onChange={(e) => {
                    const n = Number.parseInt(e.target.value.replace(/[^\d]/g, ''), 10);
                    setPart(i, j, { qty: Number.isNaN(n) ? undefined : n });
                  }}
                />
                <input
                  className="add-input add-input-xs"
                  inputMode="decimal"
                  placeholder="prix €"
                  aria-label="Prix unitaire en euros"
                  value={p.price}
                  onChange={(e) => setPart(i, j, { price: e.target.value })}
                />
                <button type="button" className="rf-remove" aria-label="Retirer cette pièce" onClick={() => removePart(i, j)}>
                  ✕
                </button>
              </div>
            ))}
            <button type="button" className="rf-add-part" onClick={() => addPart(i)}>
              + pièce
            </button>
          </div>
        ))}
        <button type="button" className="rf-add-item" onClick={addItem}>
          + ajouter un service
        </button>
      </div>

      <div className="rf-visit">
        <input className="add-input" type="date" aria-label="Date" value={performedAt} onChange={(e) => setPerformedAt(e.target.value)} />
        <input className="add-input add-input-sm" inputMode="numeric" placeholder="relevé" value={usageValue} onChange={(e) => setUsageValue(e.target.value)} />
        <select className="add-input add-input-sm" aria-label="Unité" value={usageUnit} onChange={(e) => setUsageUnit(e.target.value)}>
          <option value="km">km</option>
          <option value="mi">mi</option>
          <option value="h">h</option>
        </select>
        <input className="add-input add-input-sm" inputMode="decimal" placeholder="€" value={cost} onChange={(e) => setCost(e.target.value)} />
        <input className="add-input add-input-grow" placeholder="effectué par" value={performedBy} onChange={(e) => setPerformedBy(e.target.value)} />
      </div>

      <textarea className="add-input rf-notes" placeholder="notes" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />

      <div className="rf-actions">
        <button type="button" className="rf-cancel" onClick={onCancel}>
          Annuler
        </button>
        <button type="submit" className="add-submit" disabled={!valid}>
          {submitLabel}
        </button>
      </div>
    </form>
  );
}
