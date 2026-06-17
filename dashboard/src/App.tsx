import { useCallback, useReducer, useState } from 'react';
import { postTurn } from './agent';
import { Composer } from './components/Composer';
import { Sidebar } from './components/Sidebar';
import { Toasts } from './components/Toasts';
import { TopBar } from './components/TopBar';
import { VehicleDetail } from './components/VehicleDetail';
import { initialState } from './mock';
import { reduce } from './state';
import { useMercure, type DomainEvent } from './useMercure';
import type { VehicleId } from './types';

const API = import.meta.env.VITE_API_BASE ?? '';

export function App() {
  const [state, dispatch] = useReducer(reduce, initialState);
  const [busy, setBusy] = useState(false);

  const selected = state.vehicles.find((v) => v.id === state.selectedId) ?? state.vehicles[0];

  const send = useCallback(async (instruction: string) => {
    setBusy(true);
    try {
      const actions = await postTurn(instruction);
      actions.forEach((action) => dispatch({ kind: 'action', action }));
    } catch (e) {
      dispatch({
        kind: 'action',
        action: { type: 'toast', tone: 'alarm', message: `Échec de la requête : ${(e as Error).message}` },
      });
    } finally {
      setBusy(false);
    }
  }, []);

  const onEvent = useCallback((e: DomainEvent) => {
    dispatch({ kind: 'live', message: `${e.kind}/${e.event} · ${e.vehicle.slice(0, 8)}…` });
    dispatch({
      kind: 'action',
      action: { type: 'toast', tone: 'info', message: `Mise à jour live : ${e.kind} ${e.event}` },
    });
  }, []);

  useMercure([`${API}/garage/vehicles`, `${API}/garage/vehicles/${state.selectedId}`], onEvent);

  const onSelect = (id: VehicleId) => dispatch({ kind: 'action', action: { type: 'selectVehicle', id } });

  return (
    <div className="app">
      <TopBar />
      <div className="body">
        <Sidebar vehicles={state.vehicles} selectedId={state.selectedId} onSelect={onSelect} />
        <main className="canvas">
          <div className="scroll">
            <VehicleDetail vehicle={selected} />
          </div>
          <Composer
            suggestions={state.suggestions}
            busy={busy}
            onSend={send}
          />
        </main>
      </div>
      <Toasts toasts={state.toasts} live={state.live} onDismiss={(id) => dispatch({ kind: 'dismissToast', id })} />
    </div>
  );
}
