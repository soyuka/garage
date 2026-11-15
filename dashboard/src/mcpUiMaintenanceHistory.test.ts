// @vitest-environment jsdom
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { beforeAll, describe, expect, it } from 'vitest';

// The MCP Apps fragment api/ serves (api/resources/mcp-ui/maintenance-history.html) is plain browser JS
// with no runner of its own on the PHP side, so its message bridge is exercised here.
const html = readFileSync(resolve(process.cwd(), '../api/resources/mcp-ui/maintenance-history.html'), 'utf8');

function toolResult(usageValue: unknown) {
  return {
    jsonrpc: '2.0',
    method: 'ui/notifications/tool-result',
    params: { structuredContent: { status: 'ok', vehicle: { make: 'Renault' }, records: [{ type: 'oil_change', performedAt: '2026-01-01', usage: { value: usageValue, unit: 'km' } }] } },
  };
}

describe('maintenance-history MCP app', () => {
  beforeAll(() => {
    document.documentElement.innerHTML = html.replace(/^<!DOCTYPE html>/i, '');
    const script = document.querySelector('script')?.textContent ?? '';
    new Function(script)();
    document.dispatchEvent(new Event('DOMContentLoaded'));
  });

  it('ignores a message that does not come from the host frame', () => {
    window.dispatchEvent(new MessageEvent('message', { data: toolResult(1000), source: null }));
    expect(document.querySelectorAll('#timeline li')).toHaveLength(0);
  });

  it('renders the host tool result with every field escaped, usage included', () => {
    window.dispatchEvent(new MessageEvent('message', { data: toolResult('<img src=x onerror="window.pwned=1">'), source: window.parent }));
    const timeline = document.getElementById('timeline')!;
    expect(timeline.querySelectorAll('li')).toHaveLength(1);
    expect(timeline.querySelector('img')).toBeNull();
    expect(timeline.textContent).toContain('<img src=x');
  });
});
