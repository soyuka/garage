import { routeFile } from './route-input';

export const MAX_ATTACHMENTS = 3;

export interface Attached {
  files: File[];
  error: string | null;
}

// Attaching only collects: nothing is read or sent until the user presses Chercher.
export function addAttachments(current: readonly File[], incoming: readonly File[]): Attached {
  const files = [...current];
  let error: string | null = null;
  for (const file of incoming) {
    const kind = routeFile(file);
    if (kind.kind === 'unsupported') {
      error = kind.reason === 'size' ? 'PDF trop lourd (10 Mo maximum).' : 'Format non pris en charge : photo, vidéo courte ou PDF.';
    } else if (files.length >= MAX_ATTACHMENTS) {
      error = `${MAX_ATTACHMENTS} fichiers au plus.`;
    } else {
      files.push(file);
    }
  }
  return { files, error };
}
