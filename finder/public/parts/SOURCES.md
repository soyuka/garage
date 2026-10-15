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
| `alternator.glb` | `Alternator_Body` | 164 × 138 × 165 | 32561 |
| `battery.glb` | `Battery_AGM` | 133 × 181 × 193 | 1746 |
| `brake-master-booster.glb` | `BrakeMasterBooster` | 210 × 238 × 222 | 7940 |
| `clutch-disc.glb` | `ClutchDisc` | 201 × 30 × 201 | 7408 |
| `clutch-pressure-plate.glb` | `PressurePlate` | 258 × 43 × 258 | 13962 |
| `coil-spring.glb` | `main_spring` | 85 × 153 × 85 | 4530 |
| `compression-ring.glb` | `CompressionRing` | 83 × 1 × 83 | 404 |
| `connecting-rod.glb` | `Rod` | 74 × 181 × 22 | 3270 |
| `coolant-temperature-sensor.glb` | `CLTTempSensor` | 88 × 39 × 82 | 3258 |
| `cooling-fan.glb` | `CoolingFan` | 645 × 82 × 404 | 14151 |
| `crankshaft-pulley-damper.glb` | `Damper` | 149 × 40 × 149 | 7320 |
| `front-brake-pad.glb` | `BrakePad_1_Full` | 57 × 12 × 110 | 1008 |
| `front-brake-web.glb` | `front-brake` | 254 × 123 × 254 | 16168 |
| `front-lower-control-arm.glb` | `Front_LCA_1` | 351 × 92 × 424 | 15418 |
| `front-shock-absorber.glb` | `Shock_body` | 79 × 525 × 79 | 8480 |
| `front-shock-bushing.glb` | `FrontShock_Bushing` | 29 × 13 × 29 | 576 |
| `front-sway-bar.glb` | `FrontSwayBar_1_125` | 996 × 29 × 267 | 5760 |
| `front-upper-control-arm.glb` | `Front_UCA_1` | 310 × 84 × 259 | 8543 |
| `fuel-filter.glb` | `FuelFilter` | 121 × 180 × 87 | 4574 |
| `fuel-rail.glb` | `FuelRail` | 134 × 81 × 325 | 6308 |
| `idler-pulley.glb` | `Idler` | 52 × 31 × 52 | 712 |
| `ignition-coil-boot.glb` | `Plugwires_CoilBoot` | 44 × 42 × 24 | 526 |
| `ignition-coil-pack.glb` | `CoilPack` | 167 × 86 × 133 | 11780 |
| `injector.glb` | `Injector` | 27 × 78 × 37 | 1972 |
| `intake-manifold-lower.glb` | `IntakeManifold_Bottom` | 197 × 218 × 427 | 11928 |
| `intake-manifold-upper.glb` | `IntakeManifold_Top` | 129 × 102 × 426 | 7884 |
| `lower-control-arm-bushing.glb` | `LCA_Bushing` | 52 × 68 × 52 | 1856 |
| `lower-radiator-hose.glb` | `CoolingHose_Bottom` | 163 × 265 × 380 | 3648 |
| `muffler.glb` | `OEM_Muffler` | 627 × 216 × 254 | 11994 |
| `oil-control-ring.glb` | `OilRing` | 83 × 2 × 83 | 1384 |
| `oil-cooler.glb` | `OilCooler` | 88 × 64 × 104 | 3288 |
| `oil-filter.glb` | `oil-filter` | 70 × 69 × 69 | 3176 |
| `oil-pan.glb` | `OilPan` | 461 × 181 × 343 | 23106 |
| `oil-pump.glb` | `OilPump` | 267 × 59 × 190 | 13728 |
| `outer-tie-rod-end.glb` | `OuterTieRodEnd` | 41 × 113 × 74 | 5246 |
| `oxygen-sensor.glb` | `o2Sensor` | 24 × 88 × 25 | 3714 |
| `pcv-valve.glb` | `HeadAcc_PCV_Valve` | 28 × 49 × 28 | 932 |
| `piston.glb` | `Piston` | 83 × 61 × 83 | 3770 |
| `radiator-cap.glb` | `RadiatorCap` | 68 × 26 × 47 | 2146 |
| `radiator.glb` | `Radiator` | 653 × 85 × 454 | 5411 |
| `rear-brake-pad.glb` | `RearPad` | 102 × 12 × 43 | 628 |
| `rear-brake-web.glb` | `rear-brake` | 250 × 144 × 264 | 8558 |
| `rear-shock-absorber.glb` | `RearShockBody` | 79 × 447 × 79 | 8472 |
| `scraper-ring.glb` | `ScraperRing` | 83 × 1 × 83 | 506 |
| `spark-plug.glb` | `SparkPlug` | 19 × 76 × 19 | 812 |
| `starter.glb` | `Starter` | 132 × 193 × 117 | 10571 |
| `steering-rack.glb` | `SteeringRack` | 1240 × 97 × 169 | 34003 |
| `strut-top-mount.glb` | `TopHat` | 122 × 47 × 149 | 6996 |
| `sway-bar-end-link.glb` | `EndLinkBody` | 20 × 62 × 19 | 1440 |
| `tensioner-pulley.glb` | `Tensioner` | 77 × 31 × 74 | 1480 |
| `thermostat.glb` | `Thermostat` | 52 × 47 × 52 | 4248 |
| `timing-belt.glb` | `TimingBelt` | 270 × 22 × 431 | 7150 |
| `upper-control-arm-bushing.glb` | `FUCA_Bushing` | 52 × 56 × 52 | 1856 |
| `upper-radiator-hose.glb` | `CoolingHose_Top` | 231 × 138 × 347 | 3648 |
| `water-pump.glb` | `WaterPumpBody` | 247 × 91 × 140 | 9030 |
| `water-pump-impeller.glb` | `WaterPumpRotating` | 74 × 103 × 74 | 1836 |
| `wrist-pin.glb` | `WristPin` | 20 × 57 × 20 | 192 |
