import { describe, it, expect } from 'vitest';
import { messages } from './messages';

describe('the agent journal reads in the interface language', () => {
  it('counts tool calls with the right plural', () => {
    expect(messages('en').journal.toolCount(1)).toBe('1 tool');
    expect(messages('en').journal.toolCount(3)).toBe('3 tools');
    expect(messages('fr').journal.toolCount(1)).toBe('1 outil');
    expect(messages('fr').journal.toolCount(3)).toBe('3 outils');
  });

  it('titles its sections and placeholders in English', () => {
    const t = messages('en').journal;
    expect(t.emptyInstruction).toBe('(empty)');
    expect(t.unknown).toBe('unknown');
    expect(t.toolCalls(2)).toBe('Tool calls (2)');
    expect(t.webSearch(1)).toBe('Web search (1)');
  });

  it('keeps the French wording it shipped with', () => {
    const t = messages('fr').journal;
    expect(t.emptyInstruction).toBe('(vide)');
    expect(t.unknown).toBe('inconnu');
    expect(t.toolCalls(2)).toBe("Appels d'outils (2)");
    expect(t.webSearch(1)).toBe('Recherche web (1)');
  });
});
