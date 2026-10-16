import { addAttachments, MAX_ATTACHMENTS } from './attachments';

const pdf = new File(['%PDF'], 'carte-grise.pdf', { type: 'application/pdf' });
const jpg = (n: string) => new File(['x'], n, { type: 'image/jpeg' });

describe('addAttachments', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('only collects the files: attaching makes no network call', () => {
    const fetch = vi.fn();
    vi.stubGlobal('fetch', fetch);
    expect(addAttachments([], [pdf, jpg('a.jpg')])).toEqual({ files: [pdf, expect.any(File)], error: null });
    expect(fetch).not.toHaveBeenCalled();
  });

  it('refuses unsupported types and oversize PDFs, keeping what was attached', () => {
    const text = new File(['x'], 'a.txt', { type: 'text/plain' });
    expect(addAttachments([pdf], [text])).toEqual({ files: [pdf], error: 'Format non pris en charge : photo, vidéo courte ou PDF.' });
    const big = { name: 'big.pdf', type: 'application/pdf', size: 11 * 1024 * 1024 } as File;
    expect(addAttachments([], [big]).error).toBe('PDF trop lourd (10 Mo maximum).');
  });

  it('stops at the attachment limit', () => {
    const full = Array.from({ length: MAX_ATTACHMENTS }, (_, i) => jpg(`${i}.jpg`));
    const out = addAttachments(full, [jpg('more.jpg')]);
    expect(out.files).toEqual(full);
    expect(out.error).toContain(String(MAX_ATTACHMENTS));
  });
});
