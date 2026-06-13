# Test queries (real-world instructions the MCP should tackle)

User-enriched list of natural-language tasks an LLM client should be able to
answer *with our MCP's help*. Each is annotated with coverage assessment against
partsapi.ru (TecDoc 2025 Q4 + АВТОНОРМЫ + ТехОбслуживание). Enrich freely.

Coverage legend:
- ✅ covered by an endpoint we have / can wire
- ⚠️ partial / coverage doubtful (obscure or vintage vehicle, spotty TecDoc data)
- ❌ out of TecDoc scope — LLM general knowledge is the better answer; MCP should
     say "no parts data" cleanly and not block the model

## Queries

1. **TORO 246-H — what type of oil?**
   ❌ Snow thrower / small engine. Not in TecDoc (automotive only). Defer to LLM.

2. **TORO 246-H — spark plug?**
   ❌ Same — small-engine equipment, out of scope.

3. **Mazda 6 2.0 L at 250 000 km — maintenance list?**
   ✅ Maintenance schedule + parts. Needs ТехОбслуживание (`toTypes`→`toParts`) and
   АВТОНОРМЫ (`GetNormsTimes` for labor). NOT yet wired.

4. **Iveco `XC-302-ZC` — correct tire mount?**
   ✅/⚠️ FR SIV plate → SIV resolves it. Iveco = CV (`getFullInfoCV`). Tire/wheel OE
   fitment coverage in TecDoc is partial — tyre size may come from vehicle tech specs.

5. **Shopping list for basic maintenance — BMW F800 GSA**
   ✅/⚠️ Motorcycle. Needs vehicle-type=moto in `getMakes` chain + `toParts`. Moto
   coverage exists but is thinner than cars.

6. **Cagiva T4 350 — oil type and amount?**
   ⚠️ Vintage moto (1980s). Oil amount = `toOils`/`GetFillVolumes`; oil spec =
   `toDopusk`. Coverage for obscure/old bikes doubtful → likely LLM fallback.

## Endpoint families relevant to these queries

- **TecDoc parts** (built): `getMakes`→`getModels`→`getCars`→`getSearchTree`→`getArticles`→`getArticle`
- **АВТОНОРМЫ** (not wired): `GetNormsMakes/Models/Motors`, `GetNormsTimes` (labor norms),
  `GetFillVolumes` (fluid capacities)
- **ТехОбслуживание / maintenance** (not wired): `toMakes/toModels/toTypes`, `toParts`
  (maintenance parts per modification), `toDopusk` (oil/fluid OEM approvals), `toOils` (fill volumes)
- **VIN / plate entry**: `VINdecode`, `decodeVINus`, `VINdecodeOE`, `getPartsbyVIN`,
  `gosnomer2vin` (RU plate→VIN only — useless for FR/BE)
- **Oil-spec search**: `getCarsByOilSpecification`, `getCarsByOilSpecification`
