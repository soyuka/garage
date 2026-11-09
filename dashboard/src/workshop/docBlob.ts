import { fetchDocumentBlob } from '../garage';

// The stage and the preview strip read the same owner-gated bytes; one fetch serves both. Both caches
// are small LRUs: a manual can weigh tens of MB, and an image preview pins an object URL until revoked.
const BLOB_CAP = 3;
const PREVIEW_CAP = 30;

function lru<V>(cap: number, evicted: (value: V) => void = () => undefined) {
  const entries = new Map<string, V>();
  return {
    get(key: string): V | undefined {
      const value = entries.get(key);
      if (value !== undefined) {
        entries.delete(key);
        entries.set(key, value);
      }
      return value;
    },
    set(key: string, value: V): void {
      entries.delete(key);
      entries.set(key, value);
      while (entries.size > cap) {
        const [oldest, gone] = entries.entries().next().value as [string, V];
        entries.delete(oldest);
        evicted(gone);
      }
    },
    drop(key: string, value: V): void {
      if (entries.get(key) === value) entries.delete(key);
    },
  };
}

const blobs = lru<Promise<Blob>>(BLOB_CAP);
const previews = lru<Promise<string | null>>(PREVIEW_CAP, (p) =>
  p.then((url) => {
    if (url?.startsWith('blob:')) URL.revokeObjectURL(url);
  }),
);
let queue: Promise<unknown> = Promise.resolve();

export function docBlob(url: string): Promise<Blob> {
  let p = blobs.get(url);
  if (!p) {
    const fetching = fetchDocumentBlob(url);
    p = fetching;
    blobs.set(url, fetching);
    fetching.catch(() => blobs.drop(url, fetching));
  }
  return p;
}

/**
 * A first-page preview as an image URL, rendered one document at a time so the strip never stalls the
 * page. A failure resolves null for this ask and is not kept: the next ask retries.
 */
export function docPreview(url: string, contentType: string, width: number, render: (blob: Blob, width: number) => Promise<string | null>): Promise<string | null> {
  const key = `${url}@${width}`;
  let p = previews.get(key);
  if (!p) {
    const run = queue.then(() => docBlob(url)).then((blob) => (contentType.startsWith('image/') ? URL.createObjectURL(blob) : render(blob, width)));
    queue = run.catch(() => null);
    const preview = run.catch(() => {
      previews.drop(key, preview);
      return null;
    });
    p = preview;
    previews.set(key, preview);
  }
  return p;
}
