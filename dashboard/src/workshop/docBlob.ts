import { fetchDocumentBlob } from '../garage';

// The stage and the preview strip read the same owner-gated bytes; one fetch serves both.
const blobs = new Map<string, Promise<Blob>>();
const previews = new Map<string, Promise<string | null>>();
let queue: Promise<unknown> = Promise.resolve();

export function docBlob(url: string): Promise<Blob> {
  let p = blobs.get(url);
  if (!p) {
    p = fetchDocumentBlob(url);
    blobs.set(url, p);
    p.catch(() => blobs.delete(url));
  }
  return p;
}

/** A first-page preview as an image URL, rendered one document at a time so the strip never stalls the page. */
export function docPreview(url: string, contentType: string, width: number, render: (blob: Blob, width: number) => Promise<string | null>): Promise<string | null> {
  const key = `${url}@${width}`;
  let p = previews.get(key);
  if (!p) {
    const run = queue.then(() => docBlob(url)).then((blob) => (contentType.startsWith('image/') ? URL.createObjectURL(blob) : render(blob, width)));
    queue = run.catch(() => null);
    p = run.catch(() => null);
    previews.set(key, p);
  }
  return p;
}
