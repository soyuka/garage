# Part meshes — provenance

Every `.glb` in this directory was extracted from the game **Wrench** (Missing Digit) and reduced to
a web build (Draco, no materials) in the local asset session (`blueprint-experiment/`, never
committed; only its final `*-web.glb` files are copied here). They are used for a private prototype
only: never sold, never redistributed.

Before any commercial or public use, these meshes must be purchased or licensed from the developer,
or replaced by assets we own (parametric geometry, CC-BY meshes with attribution, or our own
Blender models). See `finder/ROADMAP.md` § 3D assets.

## Inventory (measured 2026-10-15)

All files: glTF binary, `KHR_draco_mesh_compression`, +Y up, units in metres. Sizes are the
world-space bounding box (X × Y × Z, mm) and read as real-metre scale for the one car they come
from. **No dimension is ever derived from a mesh** (`finder/DESIGN.md` § Never Invent): cotes come
only from the catalogue fingerprint. Only the filter can (`oil-filter.glb`) is rescaled to the
fingerprint's Ø / height (`scene/filterScale.ts`), and the brake assemblies to the catalogue disc Ø.
Every other mesh is centred on its bounding box at load time and drawn at a fixed framing span
(`scene/MeshPart.tsx` `MESH_SPAN`), files untouched. Category → mesh: `finder/src/family.ts`
`MESH_RULES`.

| file | Wrench source | bbox mm | triangles |
| --- | --- | --- | --- |
| `air-filter.glb` | `AirFilter` | 138 × 172 × 139 | 2228 |
| `alternator-tensioner.glb` | `Alternator_Tensioner` | 61 × 39 × 140 | 3226 |
| `alternator.glb` | `Alternator_Body` | 164 × 138 × 165 | 32561 |
| `axle-nut.glb` | `AxleNut` | 43 × 23 × 43 | 728 |
| `axles.glb` | `Axles` | 95 × 760 × 125 | 5490 |
| `battery.glb` | `Battery_AGM` | 133 × 181 × 193 | 1746 |
| `brake-hardware-1.glb` | `BrakeHardware_1` | 24 × 20 × 20 | 1356 |
| `brake-master-booster.glb` | `BrakeMasterBooster` | 210 × 238 × 222 | 7940 |
| `clutch-disc.glb` | `ClutchDisc` | 201 × 30 × 201 | 7408 |
| `clutch-pressure-plate.glb` | `PressurePlate` | 258 × 43 × 258 | 13962 |
| `coil-spring.glb` | `main_spring` | 85 × 153 × 85 | 4530 |
| `compression-ring.glb` | `CompressionRing` | 83 × 1 × 83 | 404 |
| `connecting-rod.glb` | `Rod` | 74 × 181 × 22 | 3270 |
| `coolant-temperature-sensor.glb` | `CLTTempSensor` | 88 × 39 × 82 | 3258 |
| `cooling-fan.glb` | `CoolingFan` | 645 × 82 × 404 | 14151 |
| `cooling-hardpipe.glb` | `CoolingHardpipe` | 122 × 120 × 312 | 2648 |
| `crankshaft-pulley-damper.glb` | `Damper` | 149 × 40 × 149 | 7320 |
| `crankshaft.glb` | `CrankShaft` | 465 × 145 × 121 | 20290 |
| `differential.glb` | `Differential` | 689 × 381 × 276 | 24300 |
| `drive-shaft.glb` | `DriveShaft` | 995 × 207 × 103 | 11302 |
| `efr-turbo.glb` | `EFR_Turbo` | 257 × 183 × 292 | 25219 |
| `engine-mount.glb` | `EngineMount` | 104 × 58 × 83 | 3772 |
| `exhaust-cam.glb` | `ExhaustCam` | 53 × 53 × 443 | 7602 |
| `exhaust-valve.glb` | `ExhaustValve` | 28 × 102 × 28 | 660 |
| `front-brake-pad.glb` | `BrakePad_1_Full` | 57 × 12 × 110 | 1008 |
| `front-brake-web.glb` | `front-brake` | 254 × 123 × 254 | 16168 |
| `front-hub.glb` | `FrontHub_1` | 122 × 69 × 122 | 3990 |
| `front-lower-control-arm.glb` | `Front_LCA_1` | 351 × 92 × 424 | 15418 |
| `front-shock-absorber.glb` | `Shock_body` | 79 × 525 × 79 | 8480 |
| `front-shock-bushing.glb` | `FrontShock_Bushing` | 29 × 13 × 29 | 576 |
| `front-sway-bar.glb` | `FrontSwayBar_1_125` | 996 × 29 × 267 | 5760 |
| `front-upper-control-arm.glb` | `Front_UCA_1` | 310 × 84 × 259 | 8543 |
| `front-upright.glb` | `Front_Upright_1` | 201 × 153 × 170 | 6828 |
| `fuel-filter.glb` | `FuelFilter` | 121 × 180 × 87 | 4574 |
| `fuel-rail.glb` | `FuelRail` | 134 × 81 × 325 | 6308 |
| `fuel-sender.glb` | `FuelSender` | 232 × 329 × 339 | 23117 |
| `fuel-tank.glb` | `FuelTank` | 696 × 332 × 612 | 8956 |
| `hla.glb` | `HLA` | 30 × 24 × 30 | 436 |
| `idler-pulley.glb` | `Idler` | 52 × 31 × 52 | 712 |
| `ignition-coil-boot.glb` | `Plugwires_CoilBoot` | 44 × 42 × 24 | 526 |
| `ignition-coil-pack.glb` | `CoilPack` | 167 × 86 × 133 | 11780 |
| `injector.glb` | `Injector` | 27 × 78 × 37 | 1972 |
| `intake-cam.glb` | `IntakeCam` | 51 × 51 × 416 | 7484 |
| `intake-manifold-lower.glb` | `IntakeManifold_Bottom` | 197 × 218 × 427 | 11928 |
| `intake-manifold-upper.glb` | `IntakeManifold_Top` | 129 × 102 × 426 | 7884 |
| `intake-valve.glb` | `IntakeValve` | 33 × 102 × 33 | 654 |
| `itb-tb.glb` | `ITB_TB` | 77 × 55 × 72 | 6624 |
| `lower-control-arm-bushing.glb` | `LCA_Bushing` | 52 × 68 × 52 | 1856 |
| `lower-radiator-hose.glb` | `CoolingHose_Bottom` | 163 × 265 × 380 | 3648 |
| `muffler.glb` | `OEM_Muffler` | 627 × 216 × 254 | 11994 |
| `oem-mid-pipe.glb` | `OEM_MidPipe` | 212 × 133 × 1486 | 21192 |
| `oil-control-ring.glb` | `OilRing` | 83 × 2 × 83 | 1384 |
| `oil-cooler.glb` | `OilCooler` | 88 × 64 × 104 | 3288 |
| `oil-filter.glb` | `oil-filter` | 70 × 69 × 69 | 3176 |
| `oil-pan.glb` | `OilPan` | 461 × 181 × 343 | 23106 |
| `oil-pump.glb` | `OilPump` | 267 × 59 × 190 | 13728 |
| `outer-tie-rod-end.glb` | `OuterTieRodEnd` | 41 × 113 × 74 | 5246 |
| `oxygen-sensor.glb` | `o2Sensor` | 24 × 88 × 25 | 3714 |
| `pcv-valve.glb` | `HeadAcc_PCV_Valve` | 28 × 49 × 28 | 932 |
| `pilot-bearing.glb` | `PilotBearing` | 35 × 8 × 35 | 192 |
| `piston.glb` | `Piston` | 83 × 61 × 83 | 3770 |
| `radiator-cap.glb` | `RadiatorCap` | 68 × 26 × 47 | 2146 |
| `radiator.glb` | `Radiator` | 653 × 85 × 454 | 5411 |
| `rear-brake-pad.glb` | `RearPad` | 102 × 12 × 43 | 628 |
| `rear-brake-web.glb` | `rear-brake` | 250 × 144 × 264 | 8558 |
| `rear-hub.glb` | `RearHub` | 122 × 73 × 122 | 2584 |
| `rear-pad-hardware.glb` | `RearPadHardware` | 40 × 24 × 21 | 260 |
| `rear-shock-absorber.glb` | `RearShockBody` | 79 × 447 × 79 | 8472 |
| `rear-wheel-bearing.glb` | `RearWheelBearing` | 74 × 40 × 74 | 896 |
| `scraper-ring.glb` | `ScraperRing` | 83 × 1 × 83 | 506 |
| `spark-plug.glb` | `SparkPlug` | 19 × 76 × 19 | 812 |
| `starter-gear.glb` | `Starter_Gear` | 27 × 48 × 27 | 416 |
| `starter.glb` | `Starter` | 132 × 193 × 117 | 10571 |
| `steering-column.glb` | `SteeringColumn` | 1197 × 161 × 231 | 12126 |
| `steering-rack-bracket.glb` | `SteeringRack_Bracket` | 29 × 55 × 102 | 2608 |
| `steering-rack.glb` | `SteeringRack` | 1240 × 97 × 169 | 34003 |
| `stock-manifold.glb` | `StockManifold` | 345 × 140 × 285 | 23579 |
| `strut-top-mount.glb` | `TopHat` | 122 × 47 × 149 | 6996 |
| `sway-bar-end-link.glb` | `EndLinkBody` | 20 × 62 × 19 | 1440 |
| `tensioner-pulley.glb` | `Tensioner` | 77 × 31 × 74 | 1480 |
| `thermostat.glb` | `Thermostat` | 52 × 47 × 52 | 4248 |
| `throwout-bearing.glb` | `ThrowoutBearing` | 68 × 31 × 65 | 1836 |
| `timing-belt.glb` | `TimingBelt` | 270 × 22 × 431 | 7150 |
| `upper-control-arm-bushing.glb` | `FUCA_Bushing` | 52 × 56 × 52 | 1856 |
| `upper-radiator-hose.glb` | `CoolingHose_Top` | 231 × 138 × 347 | 3648 |
| `valve-cover-gasket.glb` | `BP_VC_Gasket` | 252 × 35 × 423 | 8730 |
| `valve-cover.glb` | `BP_ValveCover` | 289 × 79 × 485 | 20639 |
| `valve-spring.glb` | `ValveSpring` | 25 × 46 × 25 | 2208 |
| `water-pump-impeller.glb` | `WaterPumpRotating` | 74 × 103 × 74 | 1836 |
| `water-pump.glb` | `WaterPumpBody` | 247 × 91 × 140 | 9030 |
| `wheel-stud.glb` | `WheelStud` | 18 × 41 × 18 | 386 |
| `wrist-pin.glb` | `WristPin` | 20 × 57 × 20 | 192 |

## Batch 3 (2026-10-15)

29 more meshes from `blueprint-experiment/parts3/web/` (Wrench, same private-prototype terms and
licence note as above). Axes +Y up, metres, Draco, no materials, all checked by decoding the files.
`axles.glb` is the front half-shaft with its CV boot (a drive axle, not a stub axle), mapped to
"Entraînement des roues"; `drive-shaft.glb` is the straight propeller shaft, mapped to
"Entraînement des essieux". Six web builds were not shipped because no TecDoc category in the Mazda
tree names them: `PPF` (a perforated power-plant brace, not a shaft), `RearPadBackingPlate`,
`Front_Dustshield_left`, `EngineMountCup`, `EngineMountHornA`, `EngineMountHornB`.

## Batch 4 (2026-10-15)

5 more meshes from `blueprint-experiment/parts4/web/` (same terms as above), mapped against the real
Mazda tree: `front-upright` ("Fusée d'essieu"), `valve-cover` ("Culasse > Cache-soupape"),
`valve-cover-gasket` ("Joints > Cache-soupape"), `differential` ("Différentiel"), `pilot-bearing`
("Palier guide / Rondelle de butée"). Four web builds were not shipped: `Front_Upright_2` (same
knuckle, the tree has one "Fusée d'essieu" and no rear variant), `BP_ValveCoverType2` (no distinct
category), `TransPlate` (a gearbox plate; the only candidate, the automatic-gearbox "Boîtier/composants",
is the wrong part for this manual car and the gearbox body stays generic), `CatfishMufflerBody`
(performance muffler; both "Silencieux" categories are already covered by `muffler.glb`).
