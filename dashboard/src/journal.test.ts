import { afterEach, describe, expect, it, vi } from 'vitest';

// journal.ts reads through authFetch — stub it so the parser is tested in isolation.
const authFetch = vi.fn();
vi.mock('./auth', () => ({ authFetch: (...args: unknown[]) => authFetch(...args) }));

import { getTurn, getTurns } from './journal';

function jsonResponse(body: unknown): Response {
  return { ok: true, status: 200, json: async () => body } as unknown as Response;
}

afterEach(() => authFetch.mockReset());

describe('getTurns', () => {
  it('reads hydra members and normalizes @id to id', async () => {
    authFetch.mockResolvedValue(
      jsonResponse({
        member: [
          { '@id': '/agent/turns/01ABC', instruction: 'salut', status: 'completed', toolCallCount: 2, webSearchCount: 0 },
        ],
      }),
    );

    const turns = await getTurns();

    expect(turns).toHaveLength(1);
    expect(turns[0].id).toBe('/agent/turns/01ABC');
    expect(turns[0].instruction).toBe('salut');
  });

  it('falls back to the hydra:member key', async () => {
    authFetch.mockResolvedValue(jsonResponse({ 'hydra:member': [{ '@id': '/agent/turns/x', instruction: 'a' }] }));
    const turns = await getTurns();
    expect(turns[0].id).toBe('/agent/turns/x');
  });

  it('throws on a non-ok response', async () => {
    authFetch.mockResolvedValue({ ok: false, status: 403 } as Response);
    await expect(getTurns()).rejects.toThrow('journal fetch failed: 403');
  });
});

describe('getTurn', () => {
  it('normalizes @id and returns the detail payload', async () => {
    authFetch.mockResolvedValue(
      jsonResponse({
        '@id': '/agent/turns/01ABC',
        instruction: 'salut',
        status: 'completed',
        toolCalls: [{ seq: 0, name: 'toast', arguments: { message: 'ok' }, result: 'ok', at: '2026-06-21T10:00:00Z', serverSide: false }],
        rawCalls: [],
        webSearchQueries: [],
      }),
    );

    const turn = await getTurn('/agent/turns/01ABC');

    expect(turn.id).toBe('/agent/turns/01ABC');
    expect(turn.toolCalls[0].name).toBe('toast');
  });
});
