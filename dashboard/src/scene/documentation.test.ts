import { describe, it, expect } from 'vitest';
import { buildDocumentation, taskUlid } from './documentation';
import type { MaintenanceRecord, PlannedTask, VehicleDocument } from '../types';

const doc = (over: Partial<VehicleDocument>): VehicleDocument => ({
  id: '01DOC',
  kind: 'other',
  title: 'Document',
  source: 'upload',
  sourceRef: null,
  plannedTaskId: null,
  filename: 'doc.pdf',
  contentType: 'application/pdf',
  size: 1024,
  pageCount: 1,
  addedAt: '2026-10-01T10:00:00+00:00',
  url: '/garage/vehicles/01V/documents/01DOC/file',
  pagesUrl: '/garage/vehicles/01V/documents/01DOC/pages',
  ...over,
});

const task = (over: Partial<PlannedTask>): PlannedTask => ({ id: '/garage/planned-tasks/01T', type: 'clutch', parts: [], status: 'open', ...over });

const record = (over: Partial<MaintenanceRecord>): MaintenanceRecord => ({
  id: '/garage/maintenance-records/01R',
  items: [{ type: 'oil_change', parts: [] }],
  performedAt: '2026-06-01',
  source: 'user',
  ...over,
});

describe('taskUlid', () => {
  it('reads the bare ULID the documents API stores off a planned-task IRI', () => {
    expect(taskUlid('/garage/planned-tasks/01KW7')).toBe('01KW7');
    expect(taskUlid('01KW7')).toBe('01KW7');
  });
});

describe('buildDocumentation', () => {
  it('groups in the fixed order Devis, Manuels, Notes techniques, Factures, Autres — empty groups dropped', () => {
    const model = buildDocumentation(
      [
        doc({ id: 'o', kind: 'other' }),
        doc({ id: 'n', kind: 'technical_note' }),
        doc({ id: 'm', kind: 'manual' }),
        doc({ id: 'q', kind: 'quote' }),
      ],
      [record({ attachments: [{ id: 'b', filename: 'facture.pdf', contentType: 'application/pdf', size: 2048, url: '/garage/maintenance-records/01R/attachments/b' }] })],
      [],
      'fr',
    );
    expect(model.groups.map((g) => g.kind)).toEqual(['quote', 'manual', 'technical_note', 'bill', 'other']);
    expect(model.count).toBe(5);

    const sparse = buildDocumentation([doc({ kind: 'manual' })], [], [], 'fr');
    expect(sparse.groups.map((g) => g.kind)).toEqual(['manual']);
  });

  it('keeps the API order (newest first) inside a group', () => {
    const model = buildDocumentation(
      [doc({ id: 'nt1', kind: 'technical_note', title: 'NT 8256A' }), doc({ id: 'nt2', kind: 'technical_note', title: 'NT 8257A' })],
      [],
      [],
      'fr',
    );
    expect(model.groups[0].rows.map((r) => r.title)).toEqual(['NT 8256A', 'NT 8257A']);
  });

  it('names the OPEN planned task a quote is linked to — and nothing for a closed or unknown one', () => {
    const tasks = [
      task({ id: '/garage/planned-tasks/01OPEN', type: 'timing_belt', label: null, status: 'open' }),
      task({ id: '/garage/planned-tasks/01DONE', type: 'oil_change', status: 'done' }),
    ];
    const model = buildDocumentation(
      [
        doc({ id: 'a', kind: 'quote', plannedTaskId: '01OPEN' }),
        doc({ id: 'b', kind: 'quote', plannedTaskId: '01DONE' }),
        doc({ id: 'c', kind: 'quote', plannedTaskId: 'gone' }),
      ],
      [],
      tasks,
      'fr',
    );
    const rows = model.groups[0].rows;
    expect(rows[0].taskLabel).toBe('Courroie de distribution');
    expect(rows[1].taskLabel).toBeNull();
    expect(rows[2].taskLabel).toBeNull();
  });

  it('turns each visit bill into a Factures row carrying the visit it belongs to', () => {
    const model = buildDocumentation(
      [],
      [
        record({
          label: 'Révision annuelle',
          performedAt: '2026-06-01',
          attachments: [{ id: 'b1', filename: 'facture-garage.pdf', contentType: 'application/pdf', size: 4096, url: '/garage/maintenance-records/01R/attachments/b1' }],
        }),
        record({ id: '/garage/maintenance-records/02R', attachments: [] }),
      ],
      [],
      'fr',
    );
    expect(model.groups).toHaveLength(1);
    const [row] = model.groups[0].rows;
    expect(row).toMatchObject({
      kind: 'bill',
      title: 'facture-garage.pdf',
      sourceRef: 'Révision annuelle',
      pageCount: null,
      size: 4096,
      url: '/garage/maintenance-records/01R/attachments/b1',
      remove: { type: 'bill', url: '/garage/maintenance-records/01R/attachments/b1' },
    });
    expect(row.date).toBe('2026-06-01');
  });

  it('a document row deletes by its own id; a missing title falls back to the filename', () => {
    const model = buildDocumentation([doc({ id: '01X', kind: 'manual', title: '', filename: 'mr361.pdf', sourceRef: 'MR 361' })], [], [], 'fr');
    expect(model.groups[0].rows[0]).toMatchObject({ title: 'mr361.pdf', sourceRef: 'MR 361', remove: { type: 'document', id: '01X' } });
  });

  it('documents unavailable (null) still lists the bills and says so', () => {
    const model = buildDocumentation(
      null,
      [record({ attachments: [{ id: 'b', filename: 'f.pdf', contentType: 'application/pdf', size: 1, url: '/u' }] })],
      [],
      'fr',
    );
    expect(model.unavailable).toBe(true);
    expect(model.groups.map((g) => g.kind)).toEqual(['bill']);
  });

  it('lists the open tasks a new document may be linked to', () => {
    const model = buildDocumentation([], [], [task({ id: '/garage/planned-tasks/01A', type: 'timing_belt' }), task({ id: '/garage/planned-tasks/01B', status: 'dismissed' })], 'fr');
    expect(model.linkableTasks).toEqual([{ id: '01A', label: 'Courroie de distribution' }]);
  });
});
