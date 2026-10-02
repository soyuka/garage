import { describe, expect, it } from 'vitest';
import { documentUploadBody } from './garage';

describe('documentUploadBody', () => {
  it('carries the file and kind, and only the optional fields that were given', () => {
    const file = new File(['x'], 'devis.pdf', { type: 'application/pdf' });
    const body = documentUploadBody({ file, kind: 'quote', sourceRef: ' DV-12 ', plannedTaskId: '01T' });
    expect(body.get('file')).toBeInstanceOf(File);
    expect(body.get('kind')).toBe('quote');
    expect(body.get('sourceRef')).toBe('DV-12');
    expect(body.get('plannedTaskId')).toBe('01T');
    expect(body.has('title')).toBe(false);
  });

  it('drops blank optional fields rather than sending empty strings', () => {
    const file = new File(['x'], 'nt.pdf', { type: 'application/pdf' });
    const body = documentUploadBody({ file, kind: 'technical_note', title: '  ', sourceRef: '', plannedTaskId: null });
    expect([...body.keys()].sort()).toEqual(['file', 'kind']);
  });
});
