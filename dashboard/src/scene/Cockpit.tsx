import './cockpit.css';
import { formatReading } from '../format';
import { emptyToNull } from '../edit';
import { InlineEdit } from '../floor/InlineEdit';
import { UsageEdit } from '../floor/UsageEdit';
import type { VehicleSummary } from '../types';

// The cockpit — the ALWAYS-ON chrome over the 3D stage: a full-width top band carrying identity
// (make/model/plate/engine) + editable kilométrage. Unlike the on-demand overlays (spec constellation,
// échéance ring), this never etches out — the facts an operator needs at a glance on every visit, not
// just when the agent surfaces them. The parent (SceneView) owns placement; this component owns only
// internal styling.

/** "1.6 HDi · 9HZ", or just the one part present, or null when neither engine field is known. */
export function engineBadge(vehicle: { engineVariant?: string | null; engineCode?: string | null }): string | null {
  const parts = [vehicle.engineVariant, vehicle.engineCode].filter((p): p is string => !!p && p.trim() !== '');
  return parts.length > 0 ? parts.join(' · ') : null;
}

/** The patch fields Cockpit ever writes — a narrow view of VehiclePatch (make/model/plate/engine/usage). */
export interface CockpitPatch {
  make?: string;
  model?: string;
  plate?: string | null;
  engineVariant?: string | null;
  engineCode?: string | null;
  usageValue?: number;
  usageUnit?: string;
}

export function Cockpit({
  vehicle,
  edit,
}: {
  vehicle: VehicleSummary;
  edit?: { onPatch: (patch: CockpitPatch) => void };
}) {
  const badge = engineBadge(vehicle);

  if (!edit) {
    return (
      <div className="at-cockpit">
        <p className="at-cockpit-name">
          {vehicle.make} {vehicle.model}
        </p>
        {(vehicle.plate || badge) && (
          <p className="at-cockpit-sub">
            {vehicle.plate}
            {vehicle.plate && badge && <span className="at-cockpit-sep"> · </span>}
            {badge}
          </p>
        )}
        <div className="at-cockpit-km">
          <span className="at-cockpit-km-value">
            {vehicle.usageValue != null ? formatReading(vehicle.usageValue, vehicle.usageUnit) : '—'}
          </span>
        </div>
      </div>
    );
  }

  return (
    <div className="at-cockpit at-cockpit--edit">
      <div className="at-cockpit-identity">
        <p className="at-cockpit-name">
          <InlineEdit
            value={vehicle.make}
            ariaLabel="Modifier la marque"
            className="at-cockpit-name--edit"
            onCommit={(next) => edit.onPatch({ make: next })}
          />{' '}
          <InlineEdit
            value={vehicle.model}
            ariaLabel="Modifier le modèle"
            className="at-cockpit-name--edit"
            onCommit={(next) => edit.onPatch({ model: next })}
          />
        </p>
        <p className="at-cockpit-sub">
          <InlineEdit
            value={vehicle.plate ?? ''}
            placeholder="+ plaque"
            ariaLabel="Modifier la plaque d'immatriculation"
            className="at-cockpit-sub--edit"
            onCommit={(next) => edit.onPatch({ plate: emptyToNull(next) })}
          />
          <span className="at-cockpit-sep"> · </span>
          <InlineEdit
            value={vehicle.engineVariant ?? ''}
            placeholder="+ variante"
            ariaLabel="Modifier la variante moteur"
            className="at-cockpit-sub--edit"
            onCommit={(next) => edit.onPatch({ engineVariant: emptyToNull(next) })}
          />
          <span className="at-cockpit-sep"> · </span>
          <InlineEdit
            value={vehicle.engineCode ?? ''}
            placeholder="+ code moteur"
            ariaLabel="Modifier le code moteur"
            className="at-cockpit-sub--edit"
            onCommit={(next) => edit.onPatch({ engineCode: emptyToNull(next) })}
          />
        </p>
      </div>
      <div className="at-cockpit-km">
        <UsageEdit
          value={vehicle.usageValue ?? null}
          unit={vehicle.usageUnit ?? null}
          vehicleType={vehicle.vehicleType}
          onCommit={(value, unit) => edit.onPatch({ usageValue: value, usageUnit: unit })}
        />
      </div>
    </div>
  );
}
