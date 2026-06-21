import { useState } from 'react';
import type { MaintenanceRecordInput } from '../garage';
import { serviceLabel, SERVICE_TYPES } from '../labels';
import type { MaintenanceRecord } from '../types';

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

/**
 * Past maintenance as a horizontal strip of compact event cards (newest first) — the read-only
 * counterpart of the car rail, deliberately simpler. A disclosure reveals the manual "log a past
 * service" form.
 */
export function PastMaintenanceRail({
  records,
  loading = false,
  onAddRecord,
}: {
  records: MaintenanceRecord[] | undefined;
  loading?: boolean;
  onAddRecord: (input: MaintenanceRecordInput) => void;
}) {
  const [adding, setAdding] = useState(false);
  const list = records ?? [];

  return (
    <div className="hist">
      <div className="panel-title">
        <span className="t">Entretiens passés</span>
        <button type="button" className="task-clean" aria-expanded={adding} onClick={() => setAdding((a) => !a)}>
          {adding ? 'Annuler' : '+ Ajouter un entretien'}
        </button>
      </div>

      {adding ? <AddRecordForm onSubmit={(input) => { onAddRecord(input); setAdding(false); }} /> : null}

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
          {list.map((r) => (
            <div key={r.id} className="hist-card">
              <div className="hist-card-head">
                <span className="hist-type">{(r.items ?? []).map((it) => serviceLabel(it.type)).join(' · ') || '—'}</span>
                <span className="hist-date">{r.performedAt}</span>
              </div>
              {r.label ? <div className="hist-label">{r.label}</div> : null}
              <div className="hist-meta">
                {reading(r.usageValue, r.usageUnit) ? <span>{reading(r.usageValue, r.usageUnit)}</span> : null}
                {money(r.costCents, r.currency) ? <span>· {money(r.costCents, r.currency)}</span> : null}
                {r.performedBy ? <span>· {r.performedBy}</span> : null}
              </div>
              {r.notes ? <div className="hist-notes">{r.notes}</div> : null}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function AddRecordForm({ onSubmit }: { onSubmit: (input: MaintenanceRecordInput) => void }) {
  const [type, setType] = useState('oil_change');
  const [performedAt, setPerformedAt] = useState('');
  const [usageValue, setUsageValue] = useState('');
  const [usageUnit, setUsageUnit] = useState('km');
  const [cost, setCost] = useState('');
  const [notes, setNotes] = useState('');

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!type.trim() || !performedAt) return;
    const mileage = Number.parseInt(usageValue.replace(/[^\d]/g, ''), 10);
    const price = Number.parseFloat(cost.replace(',', '.'));
    onSubmit({
      type: type.trim(),
      performedAt,
      usageValue: Number.isNaN(mileage) ? null : mileage,
      usageUnit: Number.isNaN(mileage) ? null : usageUnit,
      cost: Number.isNaN(price) ? null : price,
      notes: notes.trim() || null,
    });
  };

  return (
    <form className="add-form" onSubmit={submit}>
      <input list="service-types" className="add-input" placeholder="type (ex. oil_change)" value={type} onChange={(e) => setType(e.target.value)} />
      <datalist id="service-types">
        {SERVICE_TYPES.map((t) => (
          <option key={t} value={t}>
            {serviceLabel(t)}
          </option>
        ))}
      </datalist>
      <input className="add-input" type="date" aria-label="Date" value={performedAt} onChange={(e) => setPerformedAt(e.target.value)} />
      <input className="add-input add-input-sm" inputMode="numeric" placeholder="km" value={usageValue} onChange={(e) => setUsageValue(e.target.value)} />
      <select className="add-input add-input-sm" aria-label="Unité" value={usageUnit} onChange={(e) => setUsageUnit(e.target.value)}>
        <option value="km">km</option>
        <option value="mi">mi</option>
        <option value="h">h</option>
      </select>
      <input className="add-input add-input-sm" inputMode="decimal" placeholder="€" value={cost} onChange={(e) => setCost(e.target.value)} />
      <input className="add-input add-input-grow" placeholder="notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
      <button type="submit" className="add-submit" disabled={!type.trim() || !performedAt}>
        Ajouter
      </button>
    </form>
  );
}
