// @vitest-environment jsdom
import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { setCurrentLocale } from '../i18n/locale';
import { messages } from '../i18n/messages';
import type { MaintenanceAttachment, MaintenanceRecord, VehicleBundle } from '../types';
import { JobEditActions, VisitEditor } from './Editors';
import { EditContext, offlineEdits, type EditHost, type WorkshopEdits } from './edits';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const t = messages('en');
const e = t.workshop.edit;
const VEHICLE = '/garage/vehicles/v1';
const RECORD = '/garage/maintenance-records/r1';

const record: MaintenanceRecord = {
  id: RECORD,
  performedAt: '2026-10-02',
  items: [{ type: 'oil-change', label: null, parts: [] }],
  attachments: [],
  source: 'manual',
} as unknown as MaintenanceRecord;

function bundle(): VehicleBundle {
  return { vehicle: { '@id': VEHICLE, usageUnit: 'km', vehicleType: 'car' }, forecast: {}, records: [record], documents: [] } as unknown as VehicleBundle;
}

let host: HTMLDivElement;
let root: Root;

function mount(recordId: string | null, edits: WorkshopEdits) {
  const editHost: EditHost = { edits, open: () => undefined, close: vi.fn() };
  act(() => {
    root.render(<VisitEditor host={editHost} bundle={bundle()} recordId={recordId} onOpenBill={() => undefined} />);
  });
  return editHost;
}

function pickBill(file: File) {
  const input = host.querySelector<HTMLInputElement>('input[type="file"]')!;
  Object.defineProperty(input, 'files', { configurable: true, value: [file] });
  act(() => {
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });
}

function deferred<T>() {
  let resolve!: (v: T) => void;
  let reject!: (e: Error) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

beforeEach(() => {
  setCurrentLocale('en');
  host = document.createElement('div');
  document.body.append(host);
  root = createRoot(host);
});

afterEach(() => {
  act(() => root.unmount());
  host.remove();
});

describe('VisitEditor bills', () => {
  it('uploads a bill on pick for a visit that already exists, without saving the form', async () => {
    const upload = deferred<MaintenanceAttachment>();
    const attachBill = vi.fn(() => upload.promise);
    const saveVisit = vi.fn(() => Promise.resolve());
    mount(RECORD, { ...offlineEdits('offline'), attachBill, saveVisit });

    const file = new File(['%PDF-1.4'], 'facture.pdf', { type: 'application/pdf' });
    pickBill(file);

    expect(attachBill).toHaveBeenCalledWith(VEHICLE, RECORD, file);
    expect(saveVisit).not.toHaveBeenCalled();
    expect(host.textContent).toContain(e.billUploading);

    await act(async () => upload.resolve({ id: 'a1', filename: 'facture.pdf', contentType: 'application/pdf', size: 8, url: `${RECORD}/attachments/a1` }));

    expect(host.textContent).not.toContain(e.billUploading);
    expect(Array.from(host.querySelectorAll('button')).some((b) => b.getAttribute('aria-label') === e.openBill('facture.pdf'))).toBe(true);
  });

  it('shows a failed bill upload on its row', async () => {
    const attachBill = vi.fn(() => Promise.reject(new Error('413')));
    mount(RECORD, { ...offlineEdits('offline'), attachBill });

    await act(async () => pickBill(new File(['x'], 'big.pdf', { type: 'application/pdf' })));

    expect(host.textContent).toContain(e.billFailed('413'));
  });

  it('keeps bills pending on a new visit and says they go up with the save', () => {
    const attachBill = vi.fn(() => Promise.reject(new Error('should not be called')));
    mount(null, { ...offlineEdits('offline'), attachBill });

    pickBill(new File(['x'], 'facture.pdf', { type: 'application/pdf' }));

    expect(attachBill).not.toHaveBeenCalled();
    expect(host.textContent).toContain('facture.pdf');
    expect(host.textContent).toContain(e.billPending);
  });

  it('names the blocking field when the save fails validation', () => {
    const saveVisit = vi.fn(() => Promise.resolve());
    mount(null, { ...offlineEdits('offline'), saveVisit });

    act(() => host.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));

    expect(saveVisit).not.toHaveBeenCalled();
    expect(host.querySelector('[role="alert"]')?.textContent).toContain(e.errors.items);
  });
});

describe('JobEditActions done', () => {
  it('opens a new visit seeded with the job, its planned parts and the current reading', () => {
    const open = vi.fn();
    const editHost: EditHost = { edits: offlineEdits('offline'), open, close: vi.fn() };
    const b = { ...bundle(), vehicle: { ...bundle().vehicle, usageValue: 90000 } } as VehicleBundle;
    const parts = [{ id: 'p1', ref: 'HF160', qty: 1 }];
    act(() => {
      root.render(
        <EditContext.Provider value={editHost}>
          <JobEditActions job={{ type: 'oil_filter', taskId: null, label: null, parts }} bundle={b} />
        </EditContext.Provider>,
      );
    });
    const done = [...host.querySelectorAll('button')].find((el) => el.textContent === e.done);
    act(() => done?.click());
    expect(open).toHaveBeenCalledWith({ kind: 'visit', recordId: null, seed: { reading: 90000, type: 'oil_filter', label: null, parts } });
  });
});
