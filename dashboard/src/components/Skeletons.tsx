import { Card } from '../design/components/Card';

/** A single shimmer placeholder bar. Width/height are caller-controlled so it traces the slot it
 * stands in for. */
export function Skel({ w, h = 12, style }: { w: number | string; h?: number | string; style?: React.CSSProperties }) {
  return <span className="skel" style={{ width: w, height: h, ...style }} />;
}

/** The car-rail card frame with its text slots stubbed as shimmer bars — same outer markup as a real
 * card so the frame is identical and only the content swaps in. */
export function CarCardSkeleton() {
  return (
    <div className="car-card hud-panel" aria-hidden="true">
      <span className="car-card-ini">
        <Skel w={18} h={12} />
      </span>
      <span className="car-card-body">
        <Skel w="70%" h={13} style={{ marginBottom: 5 }} />
        <Skel w="45%" h={11} />
      </span>
      <span className="car-card-status skel" />
    </div>
  );
}

/** Shimmer rows for the checklist while a vehicle's tasks load (on select / after a write). Sits
 * inside the real Tâches panel so only the list swaps, not the frame. */
export function TaskListSkeleton() {
  return (
    <div className="tasks" aria-hidden="true">
      {[0, 1, 2].map((i) => (
        <div key={i} className="task-row" style={{ borderColor: 'transparent' }}>
          <Skel w={20} h={20} style={{ borderRadius: 'var(--mpa-radius-pill)' }} />
          <Skel w={`${64 - i * 14}%`} h={14} />
          <Skel w={48} h={12} />
        </div>
      ))}
    </div>
  );
}

/** Boot placeholder for the detail canvas: title, KPI strip and one panel as empty frames with
 * shimmer slots. Matches the real VehicleDetail layout so the swap is seamless. */
export function VehicleDetailSkeleton() {
  return (
    <div aria-hidden="true">
      <div className="title-row">
        <Skel w={240} h={30} />
        <Skel w={56} h={22} style={{ borderRadius: 'var(--mpa-radius-pill)' }} />
      </div>

      <Card className="kpi-card hud-panel" style={{ marginBottom: 'var(--mpa-gap-grid)' }} padding="var(--mpa-pad-card-lg) 0">
        <div className="kpi-strip">
          {[0, 1, 2, 3].map((i) => (
            <div key={i}>
              <Skel w={96} h={13} style={{ marginBottom: 14 }} />
              <Skel w={120} h={40} />
            </div>
          ))}
        </div>
      </Card>

      <Card className="hud-panel" style={{ marginTop: 'var(--mpa-gap-grid)' }}>
        <div className="panel-title">
          <Skel w={200} h={14} />
          <Skel w={70} h={14} />
        </div>
        <div className="tasks">
          {[0, 1, 2].map((i) => (
            <div key={i} className="task-row" style={{ borderColor: 'transparent' }}>
              <Skel w={20} h={20} style={{ borderRadius: 'var(--mpa-radius-pill)' }} />
              <Skel w={`${60 - i * 12}%`} h={14} />
              <Skel w={64} h={12} />
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}
