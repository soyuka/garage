# Part meshes — provenance

Every `.glb` in this directory was extracted from the game **Wrench** (Missing Digit) and reduced to
a web build (Draco, no materials) in the local asset session (`blueprint-experiment/`, never
committed; only its final `*-web.glb` files are copied here). They are used for a private prototype
only: never sold, never redistributed.

Before any commercial or public use, these meshes must be purchased or licensed from the developer,
or replaced by assets we own (parametric geometry, CC-BY meshes with attribution, or our own
Blender models). See `finder/ROADMAP.md` § 3D assets.

Sources of the non-Wrench meshes are kept in `blueprint-experiment/owned/src` (build123d scripts) and
`blueprint-experiment/hunt/` (untracked), see the two sections at the end.

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

## Owned (build123d, 2026-10-16)

Modelled in-house with build123d, owned, shippable. Generic family shapes at nominal dimensions, no brand features; a mesh never supplies a cote. Scripts: `blueprint-experiment/owned/src` (`export_all.py`, `webify.py`).

| file | what it is | key dims (mm) | triangles | bbox mm (CAD X × Y × Z) |
| --- | --- | --- | --- | --- |
| `bulb-h7.glb` | H7 halogen bulb, PX26d | glass O11.5 x ~23, flange 26x20x1, total 55 | 2960 | 26.0 × 20.0 × 55.01 |
| `bulb-p21w.glb` | P21W BA15s bayonet bulb | envelope O26, cap O15 x 18, 2 pins O2, total 50 | 3923 | 26.0 × 26.0 × 49.97 |
| `bulb-w5w.glb` | W5W W2.1x9.5d wedge bulb | tube O10, wedge 9.5x3x9, total 25 | 1786 | 10.0 × 10.0 × 24.9 |
| `jerrycan-5l.glb` | 5 L oil container | 190x115x280, R15 corners, neck O42, cap O46x22, 1 mm label recess | 1926 | 190.0 × 115.0 × 280.0 |
| `brake-drum.glb` | Drum brake drum | O230, bore O203, depth 60, centre bore O57, 4x O13 on PCD 100, 8 ribs | 2846 | 230.0 × 230.0 × 60.0 |
| `brake-shoe.glb` | Drum brake shoe | R101.5, 120 deg, web 4, rim 40 wide, lining 5, 3 web holes, anchor notch | 1520 | 149.19 × 101.5 × 40.0 |
| `wheel-cylinder.glb` | Drum brake wheel cylinder | 65 body O25, flange 45x38x4 (2 holes), boots, bleed + line port | 4896 | 114.0 × 28.0 × 42.0 |
| `brake-line.glb` | Rigid brake line | tube O4.75, ~330 developed, 3 bends R16, 2 flare nuts 11 AF x 12 | 4948 | 227.0 × 91.0 × 72.7 |
| `brake-hose.glb` | Flexible brake hose | O10, ~300 long S-curve, hex+stub, banjo eye O20x8, clip plate | 3254 | 322.0 × 58.56 × 35.57 |
| `fluid-line.glb` | Oil / PS / A-C line | metal O12 (2 bends R22) + rubber O20 x ~120, 2 ferrules O24x25, flange block 30x20x10, nut | 5182 | 118.86 × 87.0 × 212.0 |
| `control-cable.glb` | Handbrake / clutch cable | inner O3, sheath O8, ~590 developed, ferrules O14x20, clip, eyelet, nipple O8x10 | 5606 | 719.31 × 61.02 × 25.09 |
| `ribbed-pulley.glb` | Poly-V pulley | O60 x 25, 6 ribs pitch 3.56, bore O17, front recess | 5040 | 60.0 × 60.0 × 25.0 |
| `freewheel-pulley.glb` | Alternator overrunning pulley | O56 x 36, 6 ribs, dome cap O40, hex rear socket | 5422 | 56.0 × 56.0 × 36.91 |
| `flywheel.glb` | Single-mass flywheel | disc O280 x 25, 120-tooth ring gear (tip O288), 6 crank holes, 6 clutch holes | 5716 | 287.99 × 287.99 × 25.0 |
| `clutch-slave-cylinder.glb` | Clutch slave cylinder | body O30 x 80, 2-hole ear, boot + pushrod, port + bleed | 4846 | 140.5 × 49.2 × 40.2 |
| `clutch-master-cylinder.glb` | Clutch master cylinder | body O28 x 100, 2-hole rear flange, reservoir spigot, outlet, pushrod + clevis | 5736 | 194.0 × 64.0 × 54.0 |
| `vacuum-pump.glb` | Brake vacuum pump | body O90 x 50, 3-ear flange, drive dog, spigot O12 x 30 w/ barb, check-valve dome | 5810 | 118.0 × 117.92 × 75.53 |
| `catalytic-converter.glb` | Catalytic converter | oval 170x110 x 300, cones to O55 pipes 60 long, 2-bolt triangular inlet flange, ring outlet flange, lambda boss O22x10 | 4330 | 560.0 × 170.0 × 115.0 |
| `expansion-tank.glb` | Coolant expansion tank | 180x110x140, neck O40 + cap, 2 spigots O16x25, slotted tab, MIN/MAX ribs | 3944 | 242.0 × 111.0 × 166.0 |
| `wiper-blade.glb` | Flat beam wiper blade | 500 long, beam 15x10 tapering, lip, adaptor 30x15x15, end caps | 228 | 512.0 × 15.0 × 44.64 |
| `horn.glb` | Disc horn | drum O90 x 35, stepped concentric front, rear stud + slotted bracket 20x2, 2-blade spade | 3484 | 107.5 × 88.0 × 90.0 |
| `fog-lamp.glb` | Round fog lamp | lens O90 + bezel, housing ~110x100 tapering back, adjust tab, rear connector socket | 1634 | 110.0 × 86.0 × 123.0 |

## Third party (2026-10-16)

Inputs from `blueprint-experiment/hunt/` (untracked; ingest scripts in `hunt/src`). Tier decides what can ship publicly: `speed-sensor` is CC BY (credit line below); every GrabCAD row is private prototype only and must be replaced before any public use (source URLs unverified where marked, author and licence unknown).

Removed 2026-10-16: `itb-tb.glb` (Wrench `ITB_TB`, a racing individual throttle body; `corps papillon` now draws `throttle-body`). Not shipped: `power-steering-pump` (no TecDoc leaf of its own, only « Crémaillère de direction/pompe » → `steering-rack`); its web glb stays in `blueprint-experiment/hunt/web`.

| file | source | author | licence | tier | triangles | bbox mm (x / y up / z) |
| --- | --- | --- | --- | --- | --- | --- |
| `speed-sensor.glb` | https://www.thingiverse.com/thing:7292591 (files/generic_reluctor.STEP) | rubenayla (Thingiverse) | Creative Commons - Attribution (version not stated in LICENSE.txt) | CC-BY, shippable with credit | 7304 | 30.0 × 16.0 × 38.5 |
| `egr-valve.glb` | https://grabcad.com/library/egr-valve-vacuum-vw-1 (unverified) | unknown | unknown (GrabCAD terms, unverified) | private prototype only, replace before public | 7154 | 51.0 × 106.0 × 50.9 |
| `ac-compressor.glb` | https://grabcad.com/library/acdelco-1520941-ac-compressor-1 | unknown (not in zip) | unknown (GrabCAD terms, unverified) | private prototype only, replace before public | 7760 | 183.4 × 226.0 × 143.0 |
| `wiper-motor.glb` | https://grabcad.com/library/wiper-motor-15 | unknown (not in zip) | unknown (GrabCAD terms, unverified) | private prototype only, replace before public | 7760 | 209.0 × 125.8 × 88.5 |
| `gearbox.glb` | https://grabcad.com/library/quaife-qtek5-52g-79g-61g-transaxle-gearbox-1 | unknown (not in zip) | unknown (GrabCAD terms, unverified) | private prototype only, replace before public | 11639 | 633.3 × 323.0 × 295.1 |
| `timing-chain-kit.glb` | https://grabcad.com/library/engine-timing-chain-motion-simulation (unverified) | unknown (not in zip) | unknown (GrabCAD terms, unverified) | private prototype only, replace before public | 11380 | 36.0 × 355.1 × 111.2 |
| `thermostat-housing.glb` | https://grabcad.com/library/automotive-coolant-thermostat-housing-engine-cooling-system-cad-model-1 | unknown (not in zip) | unknown (GrabCAD terms, unverified) | private prototype only, replace before public | 7759 | 72.1 × 50.0 × 42.6 |
| `throttle-body.glb` | https://grabcad.com/library/throttle-body-5 | unknown (not in zip) | unknown (GrabCAD terms, unverified) | private prototype only, replace before public | 7759 | 105.5 × 124.5 × 73.0 |
| `intercooler.glb` | https://grabcad.com/library/intercooler-11 | unknown (not in zip) | unknown (GrabCAD terms, unverified) | private prototype only, replace before public | 7760 | 460.2 × 149.6 × 82.1 |

Credit line (CC BY): "Generic reluctor sensor" by Thingiverse user rubenayla (https://www.thingiverse.com/thing:7292591), licensed under Creative Commons - Attribution.
