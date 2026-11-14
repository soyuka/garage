// @vitest-environment jsdom
import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { setCurrentLocale } from '../i18n/locale';
import { messages } from '../i18n/messages';
import type { VehicleBundle, VehicleDocument } from '../types';
import { DocumentsPlane } from './Documents';
import { EditContext, offlineEdits, type EditHost } from './edits';
import type { Modules } from './modules';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const t = messages('en');
const VEHICLE = '/garage/vehicles/v1';

function doc(id: string): VehicleDocument {
  return {
    id,
    kind: 'manual',
    title: `Manual ${id}`,
    source: 'upload',
    sourceRef: null,
    plannedTaskId: null,
    filename: `${id}.pdf`,
    contentType: 'application/pdf',
    size: 1000,
    pageCount: 3,
    addedAt: '2026-11-01T10:00:00+00:00',
    url: `/garage/vehicles/v1/documents/${id}/file`,
    pagesUrl: `/garage/vehicles/v1/documents/${id}/pages`,
  };
}

function bundleWith(documents: VehicleDocument[]): VehicleBundle {
  return { vehicle: { '@id': VEHICLE }, forecast: {}, records: [], documents } as unknown as VehicleBundle;
}

let host: HTMLDivElement;
let root: Root;

function mount(bundle: VehicleBundle, edits: EditHost['edits']) {
  const editHost: EditHost = { edits, open: () => undefined, close: () => undefined };
  act(() => {
    root.render(
      <EditContext.Provider value={editHost}>
        <DocumentsPlane
          bundle={bundle}
          modules={{ documentsUnavailable: false } as Modules}
          viewer={null}
          active={false}
          searches={[]}
          settled={null}
          reduced
          onOpenRow={() => undefined}
          onOpenHit={() => undefined}
        />
      </EditContext.Provider>,
    );
  });
}

function button(label: string): HTMLButtonElement {
  const found = Array.from(host.querySelectorAll('button')).find((b) => b.textContent?.trim() === label);
  if (!found) throw new Error(`no button "${label}" in: ${host.innerHTML}`);
  return found;
}

function field<E extends Element>(label: string): E {
  const wrap = Array.from(host.querySelectorAll('label.w-field')).find((l) => l.querySelector('.w-field-label')?.textContent === label);
  const control = wrap?.querySelector<E & Element>('input:not([type="file"]), select');
  if (!control) throw new Error(`no field "${label}"`);
  return control as E;
}

function pickFile(input: HTMLInputElement, file: File) {
  Object.defineProperty(input, 'files', { configurable: true, value: [file] });
  act(() => {
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });
}

function setValue(el: HTMLInputElement | HTMLSelectElement, value: string) {
  const proto = el instanceof HTMLSelectElement ? HTMLSelectElement.prototype : HTMLInputElement.prototype;
  Object.getOwnPropertyDescriptor(proto, 'value')?.set?.call(el, value);
  act(() => {
    el.dispatchEvent(new Event(el instanceof HTMLSelectElement ? 'change' : 'input', { bubbles: true }));
  });
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

describe('DocumentsPlane: adding a document', () => {
  it('offers the add control beside the "no documents" line', () => {
    mount(bundleWith([]), offlineEdits('offline'));
    expect(host.textContent).toContain(t.workshop.empty.documents);
    expect(button(t.workshop.documents.upload.open)).toBeTruthy();
  });

  it('offers the add control when documents are listed', () => {
    mount(bundleWith([doc('d1')]), offlineEdits('offline'));
    expect(button(t.workshop.documents.upload.open)).toBeTruthy();
  });

  it('uploads the picked file with the chosen kind, title and reference', async () => {
    const uploadDocument = vi.fn(() => Promise.resolve());
    mount(bundleWith([]), { ...offlineEdits('offline'), uploadDocument });

    act(() => button(t.workshop.documents.upload.open).click());
    const form = host.querySelector('form');
    expect(form).not.toBeNull();
    const file = new File(['%PDF-1.4'], 'mr361.pdf', { type: 'application/pdf' });
    pickFile(form!.querySelector<HTMLInputElement>('input[type="file"]')!, file);
    setValue(field<HTMLSelectElement>(t.workshop.documents.upload.kind), 'technical_note');
    setValue(field<HTMLInputElement>(t.workshop.documents.upload.title), 'Clutch note');
    setValue(field<HTMLInputElement>(t.workshop.documents.upload.sourceRef), 'NT 8204');

    await act(async () => {
      button(t.workshop.documents.upload.submit).click();
    });

    expect(uploadDocument).toHaveBeenCalledWith(VEHICLE, { file, kind: 'technical_note', title: 'Clutch note', sourceRef: 'NT 8204' });
    expect(host.querySelector('form')).toBeNull();
  });

  it('keeps the form open and says why when the upload fails', async () => {
    const uploadDocument = vi.fn(() => Promise.reject(new Error('too large')));
    mount(bundleWith([]), { ...offlineEdits('offline'), uploadDocument });

    act(() => button(t.workshop.documents.upload.open).click());
    pickFile(host.querySelector<HTMLInputElement>('form input[type="file"]')!, new File(['x'], 'a.pdf', { type: 'application/pdf' }));
    await act(async () => {
      button(t.workshop.documents.upload.submit).click();
    });

    expect(host.querySelector('form')).not.toBeNull();
    expect(host.querySelector('[role="alert"]')?.textContent).toContain(t.workshop.documents.upload.failed('too large'));
  });

  it('does not send without a file', async () => {
    const uploadDocument = vi.fn(() => Promise.resolve());
    mount(bundleWith([]), { ...offlineEdits('offline'), uploadDocument });

    act(() => button(t.workshop.documents.upload.open).click());
    await act(async () => {
      button(t.workshop.documents.upload.submit).click();
    });

    expect(uploadDocument).not.toHaveBeenCalled();
    expect(host.querySelector('[role="alert"]')?.textContent).toContain(t.workshop.documents.upload.noFile);
  });
});
