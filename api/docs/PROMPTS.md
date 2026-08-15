# Canonical test prompts

Hand-run scenarios for the agent / MCP flow. French, as a real user would phrase them.

## Maintenance forecast (milestone)
> Ma Mazda immatriculée "xb-301-zb" viens de passer les 250 000 km ! Quoi prévoir pour
> mon entretien ?

Exercises: plate lookup → facts → grounded recommendations + planned tasks. The 250k km
milestone is the reference case behind `../../docs/archive/next_plan.md` (grounded recommendations) and
the coverage-variance note in `../../docs/archive/agent-findings.md`.

## Append to checklist
> ajoute huile moteur et la quantité dans la liste

Exercises: `plan_maintenance` upsert onto the open checklist.

## Plate lookup (cold)
> Tu peux trouver les informations sur la voiture immatriculée "XF305ZF"

Exercises: SIV plate lookup → TecDoc identity → garage write-through.

## Log a maintenance note (post-garage)
> Je reviens du garage, enregistre l'entretien sur ma voiture plaque XG306ZG, ce qui a été
> fait : vidange, filtres, niveaux

Exercises: `lookup_plate` → `list_vehicles` (match by plate) → `log_maintenance`
(`type`/`label`/`notes`/`performedAt`/`performedBy=garage`). Append-only `MaintenanceRecord`.
Open question: one combined record vs three (`oil_change`/`filters`/`fluids`) — `type` is
free-form, quality depends on the system prompt.
