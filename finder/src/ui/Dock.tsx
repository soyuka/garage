import type { ReactNode, Ref } from 'react';
import type { TextRoute } from '../route-input';
import type { Category, Vehicle } from '../types';
import { Intake } from './Intake';
import { ContextToken } from './VehicleContext';

export interface QuickAction {
  label: string;
  run: () => void;
}

interface Props {
  status: ReactNode;
  note: ReactNode;
  slot: Ref<HTMLDivElement>;
  // The result's actions, portalled in under the field.
  actions: Ref<HTMLDivElement>;
  quick: QuickAction[];
  // The car the composer searches on.
  context: Vehicle | null;
  onClearContext: () => void;
  categories: readonly Category[] | null;
  loading: boolean;
  busy: boolean;
  hasVehicle: boolean;
  autoFocus: boolean;
  // Bumped each time the user asks to type a plate: focuses the composer with a plate hint.
  plateRequest: number;
  // Bumped when the user offers the door label photo: opens the camera or the file picker.
  labelRequest: number;
  onText: (route: TextRoute, typed: string) => void | Promise<boolean>;
  attachments: readonly File[];
  onAttach: (files: File[]) => void;
  onDetach: (index: number) => void;
}

// At the bottom of the viewport on every screen but the home: the agent's line, its open question, the composer that
// starts or continues a search (text, photo, PDF, video), and under it the result's actions and quick actions.
export function Dock({ status, note, slot, actions, quick, context, onClearContext, categories, loading, busy, hasVehicle, autoFocus, plateRequest, labelRequest, onText, attachments, onAttach, onDetach }: Props) {
  return (
    <div className="dock">
      <div className="dock-inner">
        {status}
        {note}
        <div ref={slot} className="dock-slot" />
        <Intake variant="dock" categories={categories} loading={loading} initial="" busy={busy} autoFocus={autoFocus} plateRequest={plateRequest} labelRequest={labelRequest} hasVehicle={hasVehicle}
          token={context && <ContextToken vehicle={context} onRemove={onClearContext} />}
          below={
            <div className="dock-actions" data-empty={quick.length === 0 || undefined}>
              <div ref={actions} className="dock-acts" />
              {quick.length > 0 && (
                <div className="dock-quick" role="group" aria-label="Actions rapides">
                  {quick.map((q) => (
                    <button key={q.label} type="button" className="btn btn--quiet" onClick={q.run}>
                      {q.label}
                    </button>
                  ))}
                </div>
              )}
            </div>
          }
          onText={onText}
          attachments={attachments}
          onAttach={onAttach}
          onDetach={onDetach}
        />
      </div>
    </div>
  );
}
