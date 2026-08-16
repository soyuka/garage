import './provenance.css';
import { useT } from './i18n/LocaleProvider';

// The one shared glyph for C3 — reused wherever a source badge sits next to an agent-authored value
// (floor/FactsSection, scene/SpecConstellation, DossierPanel). See provenance.ts for the source test.
export function AgentMark() {
  const t = useT();
  return (
    <span className="at-agent-mark" title={t.misc.agentWritten} aria-label={t.misc.agentWritten} role="img">
      ✧
    </span>
  );
}
