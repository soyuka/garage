const EDITABLE_TAGS = new Set(['INPUT', 'TEXTAREA', 'SELECT']);

/** True when a key event lands in something the operator types into (field, select, contenteditable). */
export function isEditableTarget(target: EventTarget | null): boolean {
  const el = target as { tagName?: unknown; isContentEditable?: unknown } | null;
  if (!el) return false;
  return (typeof el.tagName === 'string' && EDITABLE_TAGS.has(el.tagName)) || el.isContentEditable === true;
}
