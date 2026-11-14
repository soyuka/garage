// @vitest-environment jsdom
import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TurnRefusedError } from '../agent';
import { setCurrentLocale } from '../i18n/locale';
import { messages } from '../i18n/messages';
import { Dock } from './Dock';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const t = messages('en').workshop.command;

let host: HTMLDivElement;
let root: Root;
let onSubmit: ReturnType<typeof vi.fn<(text: string, files: File[]) => Promise<void>>>;
const revoked: string[] = [];

function mount(agentAvailable = true) {
  act(() => {
    root.render(<Dock status={{ running: false, canCancel: false }} scope="parc" phase="idle" reduced onSubmit={onSubmit} onCancel={() => undefined} agentAvailable={agentAvailable} dictationAvailable={false} />);
  });
}

const input = () => host.querySelector<HTMLInputElement>('input.w-dock-input')!;
const picker = () => host.querySelector<HTMLInputElement>('input[type="file"]')!;
const sendButton = () => host.querySelector<HTMLButtonElement>('button.w-dock-send')!;
const chips = () => Array.from(host.querySelectorAll('.w-dock-chip'));
const labelled = (label: string) => Array.from(host.querySelectorAll('button')).find((b) => b.getAttribute('aria-label') === label);
const alert = () => host.querySelector('[role="alert"]')?.textContent ?? null;

function pick(...files: File[]) {
  Object.defineProperty(picker(), 'files', { configurable: true, value: files });
  act(() => {
    picker().dispatchEvent(new Event('change', { bubbles: true }));
  });
}

function paste(files: File[], text = '') {
  const event = new Event('paste', { bubbles: true, cancelable: true });
  Object.defineProperty(event, 'clipboardData', { value: { files, items: [], types: text ? ['text/plain'] : ['Files'], getData: () => text } });
  act(() => {
    input().dispatchEvent(event);
  });
  return event;
}

async function send() {
  await act(async () => {
    host.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  });
}

const png = (name = 'photo.png') => new File(['png'], name, { type: 'image/png' });

beforeEach(() => {
  setCurrentLocale('en');
  window.matchMedia = ((query: string) => ({ matches: false, media: query, addEventListener: () => undefined, removeEventListener: () => undefined })) as unknown as typeof window.matchMedia;
  let n = 0;
  URL.createObjectURL = vi.fn(() => `blob:thumb-${++n}`);
  revoked.length = 0;
  URL.revokeObjectURL = vi.fn((url: string) => void revoked.push(url));
  onSubmit = vi.fn<(text: string, files: File[]) => Promise<void>>(() => Promise.resolve());
  host = document.createElement('div');
  document.body.append(host);
  root = createRoot(host);
});

afterEach(() => {
  act(() => root.unmount());
  host.remove();
});

describe('Dock attachments', () => {
  it('takes a pasted image as a chip with a thumbnail, and leaves a text paste alone', () => {
    mount();

    const textPaste = paste([], 'hello');
    expect(textPaste.defaultPrevented).toBe(false);
    expect(chips()).toHaveLength(0);

    paste([png()]);

    expect(chips()).toHaveLength(1);
    expect(chips()[0].textContent).toContain('photo.png');
    expect(chips()[0].querySelector('img')?.getAttribute('src')).toBe('blob:thumb-1');
  });

  it('takes a dropped file', () => {
    mount();
    const event = new Event('drop', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'dataTransfer', { value: { files: [new File(['%PDF'], 'bill.pdf', { type: 'application/pdf' })], types: ['Files'] } });

    act(() => {
      host.querySelector('form')!.dispatchEvent(event);
    });

    expect(chips()).toHaveLength(1);
    expect(chips()[0].textContent).toContain('bill.pdf');
  });

  it('refuses a .txt and an 11 MiB file before anything is sent', async () => {
    mount();

    pick(new File(['hi'], 'notes.txt', { type: 'text/plain' }));
    expect(chips()).toHaveLength(0);
    expect(alert()).toBe(t.attachType('notes.txt'));

    pick(new File([new Uint8Array(11 * 1024 * 1024)], 'huge.pdf', { type: 'application/pdf' }));
    expect(chips()).toHaveLength(0);
    expect(alert()).toContain('huge.pdf');

    await send();
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('refuses a sixth file', () => {
    mount();

    pick(png('1.png'), png('2.png'), png('3.png'), png('4.png'), png('5.png'), png('6.png'));

    expect(chips()).toHaveLength(5);
    expect(alert()).toBe(t.attachCount(5));
  });

  it('sends with only a file and clears the chips once the turn is accepted', async () => {
    mount();
    expect(sendButton().disabled).toBe(true);

    pick(png());
    expect(input().value).toBe('');
    expect(sendButton().disabled).toBe(false);

    await send();

    expect(onSubmit).toHaveBeenCalledWith('', [expect.objectContaining({ name: 'photo.png' })]);
    expect(chips()).toHaveLength(0);
    expect(revoked).toEqual(['blob:thumb-1']);
  });

  it('keeps the chips and says why on a 415', async () => {
    onSubmit.mockRejectedValue(new TurnRefusedError('unsupported', 415, null));
    mount();
    pick(png());

    await send();

    expect(chips()).toHaveLength(1);
    expect(alert()).toBe(t.refused.unsupported);
    expect(revoked).toEqual([]);
  });

  it('removes a chip and revokes its thumbnail', () => {
    mount();
    pick(png());

    act(() => {
      labelled(t.removeAttachment('photo.png'))!.click();
    });

    expect(chips()).toHaveLength(0);
    expect(revoked).toEqual(['blob:thumb-1']);
  });

  it('revokes the thumbnails on unmount', () => {
    mount();
    pick(png());

    act(() => root.unmount());
    root = createRoot(host);

    expect(revoked).toEqual(['blob:thumb-1']);
  });

  it('cannot attach when the agent is not configured', () => {
    mount(false);

    expect(labelled(t.attach)?.disabled).toBe(true);
    paste([png()]);
    expect(chips()).toHaveLength(0);
  });
});
