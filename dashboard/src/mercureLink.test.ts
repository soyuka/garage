import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { AgentLink } from './agent';
import { linkKeeper } from './mercureLink';

// A link that sets the hub cookie carries the owner topics; a failed one (parseAgentLink(null)) none.
function link(ok: boolean | string): AgentLink {
  const topic = ok === false ? null : 'p';
  return { topic, fleetTopic: topic && 'f', sessionTopic: topic && 's', generation: 0, capabilities: { agent: true, voice: true, dictation: true, web_search: true } };
}

describe('linkKeeper', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('fetches the link once on start and refetches when a stream is refused, bumping the generation so the streams reopen with the fresh cookie', async () => {
    const fetchLink = vi.fn(async () => link(true));
    const applied: number[] = [];
    const keeper = linkKeeper(fetchLink, (l) => applied.push(l.generation));

    keeper.refresh();
    await vi.runAllTimersAsync();
    expect(applied).toEqual([1]);

    keeper.refused();
    await vi.runAllTimersAsync();
    expect(applied).toEqual([1, 2]);
    expect(fetchLink).toHaveBeenCalledTimes(2);
  });

  it('coalesces the refusals of several streams into one fetch', async () => {
    const fetchLink = vi.fn(async () => link(true));
    const keeper = linkKeeper(fetchLink, () => {});
    keeper.refresh();
    await vi.runAllTimersAsync();

    keeper.refused();
    keeper.refused();
    await vi.runAllTimersAsync();
    expect(fetchLink).toHaveBeenCalledTimes(2);
  });

  it('backs off when the hub keeps refusing fresh tokens, and resets once a stream opens', async () => {
    const fetchLink = vi.fn(async () => link(true));
    const keeper = linkKeeper(fetchLink, () => {});
    keeper.refresh();
    await vi.runAllTimersAsync();

    keeper.refused();
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchLink).toHaveBeenCalledTimes(2);

    keeper.refused();
    await vi.advanceTimersByTimeAsync(500);
    expect(fetchLink).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(1000);
    expect(fetchLink).toHaveBeenCalledTimes(3);

    keeper.opened();
    keeper.refused();
    await vi.advanceTimersByTimeAsync(0);
    expect(fetchLink).toHaveBeenCalledTimes(4);
  });

  it('keeps the current link when a refetch fails and tries again later', async () => {
    const results = [link(true), link(false), link(true)];
    const fetchLink = vi.fn(async () => results.shift() ?? link(false));
    const applied: number[] = [];
    const keeper = linkKeeper(fetchLink, (l) => applied.push(l.generation));
    keeper.refresh();
    await vi.runAllTimersAsync();

    keeper.refused();
    await vi.advanceTimersByTimeAsync(0);
    expect(applied).toEqual([1]);

    await vi.advanceTimersByTimeAsync(60_000);
    expect(applied).toEqual([1, 2]);
  });

  it('applies nothing once stopped', async () => {
    let resolve: (l: AgentLink) => void = () => {};
    const keeper = linkKeeper(() => new Promise((r) => (resolve = r)), () => applied.push('x'));
    const applied: string[] = [];
    keeper.refresh();
    keeper.stop();
    resolve(link(true));
    await vi.runAllTimersAsync();
    expect(applied).toEqual([]);
  });
});
