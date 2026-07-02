import './provenance.css';

// The one shared glyph for C3 — reused wherever a source badge sits next to an agent-authored value
// (floor/FactsSection, scene/SpecConstellation, DossierPanel). See provenance.ts for the source test.
export function AgentMark() {
  return (
    <span className="at-agent-mark" title="écrit par l'agent" aria-label="écrit par l'agent" role="img">
      ✧
    </span>
  );
}
