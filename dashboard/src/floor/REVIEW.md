# Stage B.1 — honest floor · adversarial review (the standing gate)

Three refute-by-default reviewers (honesty/legibility · French · contract-fidelity), each citing a
vision §, CONTRACT rule, or file:line. Run via the `b1-honest-floor-review` workflow. All verdicts
**concerns** (no blocker/fail). Every finding fixed before B.2 fan-out:

## Fixed

- **[honesty major] TruthPanel rows were amber-vs-green by dot color alone** → each per-system row now
  carries a TEXT tag (OK / BIENTÔT / EN RETARD) via the shared `signalTag`, mirroring the flat list.
  Grayscale-legible.
- **[honesty major] `forecast.gaps` were absent from the authoritative panel** → the TruthPanel now
  renders a "NON SUIVI · …" line, so the panel is genuinely lossless (hide the canvas → nothing lost).
- **[honesty minor] exact due date missing from the panel** → each per-system row shows the date.
- **[french + fidelity major] raw `N j` day count = false precision** (§5) → `dueDescriptor.until` is
  null on a time-only axis; the rounded `horizon` carries the time forecast. Precision is reserved for
  the usage axis (km remaining) and the overdue exception.
- **[french minor] plain space in loose strings** → `formatHorizon` ("…2 mois"), the overdue-days
  branch, and the panel context counts now bind number↔word with NBSP (U+00A0).
- **[honesty nit] no `unknown` scenario** → added a "Non suivi (gris)" fixture; renders neutral grey,
  never healthy.
- **[fidelity nit] `dueSoon` type doc said "within 30 days" only** → tightened to the §2 composite
  amber rule (30 days OR ≤10% interval), with a Stage-C verify-against-backend note.
- **[extra — found in chrome-devtools] React shorthand/non-shorthand `border` warning** on the scenario
  toggle → `btnOn` overrides the full `border` shorthand. Console clean.

## Confirmed strengths

Precise `DÉPASSÉ de 3 200 km` in BOTH surfaces from one shared `dueDescriptor` (panel + list cannot
disagree); per-system % honestly null on a time axis (shows `—`, never invented); WCAG AA passes on
the glass (mono ≈11:1); no value lives only inside a glow; THIN/NBSP are genuine U+202F/U+00A0; French
labels/accents correct.

## Verified live (chrome-devtools, fixtures)

overdue → `DÉPASSÉ de 3 200 km` + EN RETARD + date in panel & list; ambre → `NON SUIVI · Filtre à air`
in panel, time-axis row shows `—`/rounded horizon (no raw days); unknown → grey "Non suivi", no score.
Console clean across toggles. 48 tests green, tsc clean.
