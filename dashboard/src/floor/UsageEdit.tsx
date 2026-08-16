import { useEffect, useState } from 'react';
import { coerceInt, defaultUsageUnit, VEHICLE_USAGE_UNITS } from '../edit';
import { groupThousands } from '../format';
import { useLocale, useT } from '../i18n/LocaleProvider';
import { InlineEdit } from './InlineEdit';
import './floor.css';

/**
 * The re-stamp payload when the unit select changes: an existing reading is rewritten under the new
 * unit immediately (the backend requires value+unit together). No reading yet ⇒ nothing to commit.
 */
export function nextUsageOnUnitChange(value: number | null, unit: string): { value: number; unit: string } | null {
  if (value == null) return null;
  return { value, unit };
}

// The usage reading editor: a click-to-edit value paired with a unit select. The Vehicle resource
// binds App\Garage\UsageUnit (km|mi|h), so the select offers those — sending the maintenance-side
// "hours" would be rejected. Value + unit are always written together (the backend requires the pair);
// changing the unit re-stamps an existing reading immediately, a value commit carries the held unit.
export function UsageEdit({ value, unit, vehicleType, onCommit }: { value: number | null; unit: string | null; vehicleType: string; onCommit: (value: number, unit: string) => void }) {
  const [locale] = useLocale();
  const t = useT();
  const [u, setU] = useState<string>(unit ?? defaultUsageUnit(vehicleType));
  useEffect(() => {
    if (unit) setU(unit);
  }, [unit]);

  const commitValue = (raw: string) => {
    const n = coerceInt(raw);
    if (n != null) onCommit(n, u); // empty/non-numeric is a no-op — a reading can't be cleared to null
  };
  const changeUnit = (next: string) => {
    setU(next);
    const restamp = nextUsageOnUnitChange(value, next);
    if (restamp) onCommit(restamp.value, restamp.unit); // re-stamp the existing reading with the new unit
  };

  return (
    <span className="at-usage-edit">
      <InlineEdit
        value={value != null ? groupThousands(value, locale) : ''}
        placeholder={t.usage.placeholder}
        ariaLabel={t.usage.edit}
        className="at-usage-edit-val"
        onCommit={commitValue}
      />
      <select className="at-usage-edit-unit" aria-label={t.usage.unitLabel} value={u} onChange={(e) => changeUnit(e.target.value)}>
        {VEHICLE_USAGE_UNITS.map((opt) => (
          <option key={opt} value={opt}>
            {opt}
          </option>
        ))}
      </select>
    </span>
  );
}
