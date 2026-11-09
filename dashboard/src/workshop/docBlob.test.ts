import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const fetchDocumentBlob = vi.fn();
vi.mock('../garage', () => ({ fetchDocumentBlob: (url: string) => fetchDocumentBlob(url) }));

const blob = (name: string) => new Blob([name], { type: 'image/png' });

describe('docBlob / docPreview caches', () => {
  let revoked: string[];
  let minted: number;

  beforeEach(() => {
    vi.resetModules();
    fetchDocumentBlob.mockReset();
    fetchDocumentBlob.mockImplementation(async (url: string) => blob(url));
    revoked = [];
    minted = 0;
    vi.spyOn(URL, 'createObjectURL').mockImplementation(() => `blob:https://localhost/${++minted}`);
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation((u: string) => void revoked.push(u));
  });
  afterEach(() => vi.restoreAllMocks());

  it('serves a repeat read from the cache', async () => {
    const { docBlob } = await import('./docBlob');
    await docBlob('/d/1');
    await docBlob('/d/1');
    expect(fetchDocumentBlob).toHaveBeenCalledTimes(1);
  });

  it('holds at most three blobs, evicting the least recently used', async () => {
    const { docBlob } = await import('./docBlob');
    for (const u of ['/d/1', '/d/2', '/d/3']) await docBlob(u);
    await docBlob('/d/1');
    await docBlob('/d/4');
    fetchDocumentBlob.mockClear();
    await docBlob('/d/1');
    await docBlob('/d/2');
    expect(fetchDocumentBlob.mock.calls.map((c) => c[0])).toEqual(['/d/2']);
  });

  it('does not keep a failed fetch', async () => {
    const { docBlob } = await import('./docBlob');
    fetchDocumentBlob.mockRejectedValueOnce(new Error('503'));
    await expect(docBlob('/d/1')).rejects.toThrow('503');
    await expect(docBlob('/d/1')).resolves.toBeInstanceOf(Blob);
    expect(fetchDocumentBlob).toHaveBeenCalledTimes(2);
  });

  it('revokes an object-URL preview when it falls out of the cache', async () => {
    const { docPreview } = await import('./docBlob');
    const render = vi.fn();
    const first = await docPreview('/d/0', 'image/png', 100, render);
    for (let i = 1; i <= 30; i++) await docPreview(`/d/${i}`, 'image/png', 100, render);
    expect(revoked).toEqual([first]);
  });

  it('does not keep a failed preview, so the next ask retries', async () => {
    const { docPreview } = await import('./docBlob');
    const render = vi.fn().mockRejectedValueOnce(new Error('bad pdf')).mockResolvedValueOnce('data:image/png;base64,AA');
    await expect(docPreview('/d/1', 'application/pdf', 100, render)).resolves.toBeNull();
    await expect(docPreview('/d/1', 'application/pdf', 100, render)).resolves.toBe('data:image/png;base64,AA');
    expect(render).toHaveBeenCalledTimes(2);
  });
});
