import { useRef, type ReactNode, type Ref } from 'react';
import type { Starter } from '../home';
import { routeText, type TextRoute } from '../route-input';
import type { Category } from '../types';
import { Intake } from './Intake';

export interface QuickAction {
  label: string;
  run: () => void;
}

interface Props {
  status: ReactNode;
  note: ReactNode;
  slot: Ref<HTMLDivElement>;
  quick: QuickAction[];
  starters: readonly Starter[];
  categories: readonly Category[] | null;
  loading: boolean;
  busy: boolean;
  hasVehicle: boolean;
  autoFocus: boolean;
  onText: (route: TextRoute, typed: string) => void;
  onFiles: (files: File[]) => void;
}

// Always at the bottom of the viewport: the agent's line, its open question, quick actions, and the
// composer that starts or continues a search (text, photo, PDF, video).
export function Dock({ status, note, slot, quick, starters, categories, loading, busy, hasVehicle, autoFocus, onText, onFiles }: Props) {
  return (
    <div className="dock">
      <div className="dock-inner">
        {status}
        {note}
        <div ref={slot} className="dock-slot" />
        {quick.length > 0 && (
          <div className="dock-quick" role="group" aria-label="Actions rapides">
            {quick.map((q) => (
              <button key={q.label} type="button" className="btn btn--quiet" onClick={q.run}>
                {q.label}
              </button>
            ))}
          </div>
        )}
        <Intake variant="dock" categories={categories} loading={loading} initial="" busy={busy} autoFocus={autoFocus} hasVehicle={hasVehicle} onText={onText} onFiles={onFiles} />
        {starters.length > 0 && <Starters starters={starters} busy={busy} onText={onText} onFiles={onFiles} />}
      </div>
    </div>
  );
}

// Home examples under the composer: one click runs the search, or opens the file picker for a photo.
function Starters({ starters, busy, onText, onFiles }: { starters: readonly Starter[]; busy: boolean; onText: Props['onText']; onFiles: Props['onFiles'] }) {
  const picker = useRef<HTMLInputElement>(null);
  return (
    <div className="dock-starters" role="group" aria-label="Exemples">
      {starters.map((s) => (
        <button
          key={s.label}
          type="button"
          className="btn btn--quiet"
          disabled={busy}
          onClick={() => (s.kind === 'photo' ? picker.current?.click() : onText(routeText(s.label, null), s.label))}
        >
          {s.label}
        </button>
      ))}
      <input
        ref={picker}
        type="file"
        accept="image/*,application/pdf"
        className="sr-only"
        tabIndex={-1}
        aria-hidden="true"
        onChange={(e) => {
          const picked = e.target.files ? Array.from(e.target.files) : [];
          e.target.value = '';
          if (picked.length > 0) onFiles(picked);
        }}
      />
    </div>
  );
}
