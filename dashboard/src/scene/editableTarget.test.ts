import { describe, expect, it } from 'vitest';
import { isEditableTarget } from './editableTarget';

describe('isEditableTarget — keys the operator is typing are not the viewer’s', () => {
  it.each([
    ['an input', { tagName: 'INPUT' }],
    ['a textarea', { tagName: 'TEXTAREA' }],
    ['a select', { tagName: 'SELECT' }],
    ['a contenteditable', { tagName: 'DIV', isContentEditable: true }],
  ])('is true for %s', (_label, target) => {
    expect(isEditableTarget(target as unknown as EventTarget)).toBe(true);
  });

  it.each([
    ['a plain element', { tagName: 'DIV', isContentEditable: false }],
    ['a button', { tagName: 'BUTTON' }],
    ['no target', null],
    ['the window', {}],
  ])('is false for %s', (_label, target) => {
    expect(isEditableTarget(target as unknown as EventTarget | null)).toBe(false);
  });
});
