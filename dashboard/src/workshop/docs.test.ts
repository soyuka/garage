import { describe, expect, it } from 'vitest';
import type { DocRow } from './modules';
import { docKinds, openRowId, previewTitle, stripRows } from './docs';

const row = (id: string, kind: DocRow['kind'], date: string): DocRow => ({ id, title: id, kind, date, contentType: 'application/pdf', filename: `${id}.pdf`, url: `/files/${id}` });

const rows = [row('bill-2025', 'bill', '2025-11-12'), row('mr361', 'manual', '2025-05-02'), row('quote', 'quote', '2024-01-01'), row('bill-2021', 'bill', '2021-03-02')];

describe('docKinds', () => {
  it('lists the kinds present in a fixed order, bills first, with their counts', () => {
    expect(docKinds(rows)).toEqual([
      { kind: 'bill', count: 2 },
      { kind: 'manual', count: 1 },
      { kind: 'quote', count: 1 },
    ]);
  });
});

describe('stripRows', () => {
  it('keeps the newest-first order and filters by kind when one is chosen', () => {
    expect(stripRows(rows, null).map((r) => r.id)).toEqual(['bill-2025', 'mr361', 'quote', 'bill-2021']);
    expect(stripRows(rows, 'bill').map((r) => r.id)).toEqual(['bill-2025', 'bill-2021']);
  });
});

describe('openRowId', () => {
  it('marks the strip entry of the open document by its file url', () => {
    expect(openRowId(rows, { url: '/files/mr361' })).toBe('mr361');
    expect(openRowId(rows, { url: '/elsewhere' })).toBeNull();
    expect(openRowId(rows, null)).toBeNull();
  });
});

describe('previewTitle', () => {
  it('keeps what tells sibling manuals apart: the section after the dash', () => {
    expect(previewTitle('Manuel de réparation Espace IV carrosserie — Garnissage et sellerie (71A-79A)')).toBe('Garnissage et sellerie (71A-79A)');
    expect(previewTitle('Facture Garage du Centre 11-2025.pdf')).toBe('Facture Garage du Centre 11-2025.pdf');
  });
});
