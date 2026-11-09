import { describe, expect, it } from 'vitest';
import { NBSP, THIN } from '../format';
import { jobTitle, jobWhen, reading, sameLabel, sentenceCase } from './describe';
import type { JobItem } from './modules';

const job = (over: Partial<JobItem>): JobItem => ({
  id: 'oil_change',
  type: 'oil_change',
  label: null,
  state: 'planned',
  immediate: false,
  remaining: null,
  dueReading: null,
  dueDate: null,
  days: null,
  taskId: null,
  parts: [],
  ...over,
});

describe('describe', () => {
  it('formats a reading with French thin-space thousands and the unit', () => {
    expect(reading(214300, 'km', 'fr')).toBe(`214${THIN}300${NBSP}km`);
    expect(reading(312, 'h', 'en')).toBe(`312${NBSP}h`);
  });

  it('titles a job by its own label, else its service', () => {
    expect(jobTitle(job({}), 'fr')).toBe('Vidange');
    expect(jobTitle(job({ label: 'Kit + volant' }), 'fr')).toBe('Kit + volant');
  });

  it('displays a job label in one casing everywhere', () => {
    expect(jobTitle(job({ label: 'embrayage' }), 'fr')).toBe('Embrayage');
  });

  it('says when: overshoot when overdue, remaining usage first, else days, else now', () => {
    expect(jobWhen(job({ state: 'over', remaining: { value: -1500, unit: 'km' } }), 'fr')).toBe(`dépassé de 1${THIN}500${NBSP}km`);
    expect(jobWhen(job({ remaining: { value: 10700, unit: 'km' }, days: 60 }), 'fr')).toBe(`dans 10${THIN}700${NBSP}km`);
    expect(jobWhen(job({ days: 12 }), 'fr')).toBe(`dans 12${NBSP}jours`);
    expect(jobWhen(job({ state: 'over', days: -31 }), 'en')).toBe(`over by 31${NBSP}days`);
    expect(jobWhen(job({ state: 'due', immediate: true }), 'fr')).toBe('maintenant');
    expect(jobWhen(job({}), 'fr')).toBe('');
  });
});

describe('heading helpers', () => {
  it('sentence-cases a job heading without touching the rest', () => {
    expect(sentenceCase('embrayage')).toBe('Embrayage');
    expect(sentenceCase('rotule de direction intérieure')).toBe('Rotule de direction intérieure');
    expect(sentenceCase('Kit embrayage + volant')).toBe('Kit embrayage + volant');
    expect(sentenceCase('')).toBe('');
  });

  it('tells when two labels say the same thing, ignoring case and accents', () => {
    expect(sameLabel('embrayage', 'Embrayage')).toBe(true);
    expect(sameLabel('Contrôle technique', 'controle technique')).toBe(true);
    expect(sameLabel('Kit embrayage', 'Embrayage')).toBe(false);
  });
});
