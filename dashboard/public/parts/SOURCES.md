# SOURCES.md

Part meshes were decimated, stripped of all materials/images/UV maps, recentred
and rescaled from the sources below. The dashboard applies its own hologram
shader — no textures ship with these files.

- **spark-plug.glb** — "Spark Plug - Low Poly", Sketchfab download, licence not
  captured. 6000 tris. Largest dimension 0.095 m.
- **brake-disc.glb** — "Car Disc Brake", Sketchfab download, licence not
  captured. 8000 tris. Largest dimension 0.334 m.
- **battery.glb** — "Battery PSX Style", Sketchfab download, licence not
  captured. 308 tris (no decimation). Largest dimension 0.26 m.
- **oil-filter.glb** — "Oil Filter" by nodelet
  (https://sketchfab.com/3d-models/oil-filter-0d26735d9cc04fd896fd8c20482bd764),
  CC-BY-NC-4.0 (http://creativecommons.org/licenses/by-nc/4.0/) — author must
  be credited, no commercial use. 78 tris (no decimation). Largest dimension
  0.10 m.
- **engine-block.glb** — "Old Engine" by TraianDumbrava
  (https://sketchfab.com/3d-models/old-engine-c9130ed48cee420186e06b83c4c880af),
  CC-BY-4.0 (http://creativecommons.org/licenses/by/4.0/) — author must be
  credited, commercial use allowed. 3716 tris. Largest dimension 0.70 m.

## Wiring (2026-09-04)

| diagram key | file |
| --- | --- |
| `oil_filter` | `oil-filter.glb` |
| `spark_plug` | `spark-plug.glb` |
| `battery` | `battery.glb` |
| `brake_discs` (front + rear) | `brake-disc.glb`, yawed `π/2` onto the axle |
| `engine` | `engine-block.glb` |

Fit rule: the mesh's longest axis is scaled to the primitive's extent along THAT axis (`schematicBox`/
`meshFit` in `assembly.ts`), corrected 2026-09-05.

### Not wired, by decision

- `engine-v6.glb` ("V6 car engine with engine [MoreThem]" by Blendoriano, CC-BY-4.0) — refused:
  vehicle-specific detail the data cannot back (a detailed V6 under a four-cylinder Mazda would be a
  lie; no vehicle carries an engine-layout datum). Removed from disk 2026-09-05.
- `spark-plug-worn.glb` ("Old Rusty Spark Plug") — a WORN plug is a claim about the operator's plug the
  data does not hold. Removed from disk 2026-09-05.
- `fuel_filter` keeps its canister primitive — the oil-filter mesh is a different part.

### Licences

- **oil-filter.glb** — CC-BY-NC-4.0. Accepted by the owner 2026-09-04 ("personal app, any licence
  will do"); supersedes FUTURE.md's "drop oil-filter.glb".
- **spark-plug.glb / brake-disc.glb / battery.glb** — author + licence not captured; accepted on the
  same basis. Still to capture before any public ship.

## Copied from bonneref (2026-11-04, Workshop redesign)

Copied byte-for-byte, read-only, from `bonneref/public/parts/` for the Workshop's live part renders
(`dashboard/src/workshop/partMeshes.ts`). Provenance, licence status and restrictions are those of
`bonneref/public/parts/SOURCES.md`, carried over here: the extracted meshes are for a private prototype
only, never sold or redistributed, and must be purchased, licensed or replaced before any commercial or
public use (`bonneref/ROADMAP.md` § 3D assets). `flywheel.glb` is owned (build123d, generic shape).

| file | origin (bonneref SOURCES.md section) | extracted from / what it is | bbox mm | triangles |
| --- | --- | --- | --- | --- |
| `air-filter.glb` | Inventory (2026-10-15) | `AirFilter` | 138 × 172 × 139 | 2228 |
| `clutch-disc.glb` | Inventory (2026-10-15) | `ClutchDisc` | 201 × 30 × 201 | 7408 |
| `clutch-pressure-plate.glb` | Inventory (2026-10-15) | `PressurePlate` | 258 × 43 × 258 | 13962 |
| `front-brake-pad.glb` | Inventory (2026-10-15) | `BrakePad_1_Full` | 57 × 12 × 110 | 1008 |
| `fuel-filter.glb` | Inventory (2026-10-15) | `FuelFilter` | 121 × 180 × 87 | 4574 |
| `thermostat.glb` | Inventory (2026-10-15) | `Thermostat` | 52 × 47 × 52 | 4248 |
| `timing-belt.glb` | Inventory (2026-10-15) | `TimingBelt` | 270 × 22 × 431 | 7150 |
| `water-pump.glb` | Inventory (2026-10-15) | `WaterPumpBody` | 247 × 91 × 140 | 9030 |
| `flywheel.glb` | Owned (build123d, 2026-10-16) | single-mass flywheel, O280 x 25, 120-tooth ring | 288 × 288 × 25 | 5716 |

These are drawn in the Workshop's clay/studio material (`workshop/CarStage.tsx` `clayMaterial`), not
the hologram shader. Mapping service type / part words → file: `workshop/partMeshes.ts`.

## Copied from bonneref, batch 2 (2026-11-04, slice 1b part mapping)

Copied byte-for-byte, read-only, from `bonneref/public/parts/` so the part rows can show the part they name
(`dashboard/src/workshop/partMeshes.ts`, words → file). Provenance and licence status are those of
`bonneref/public/parts/SOURCES.md`, carried over below row for row: game-extracted ("Inventory") meshes are
private-prototype only and must be licensed or replaced before any public or commercial use; "Owned" meshes
are in-house build123d models; the "Third party" row keeps the licence recorded in bonneref's SOURCES.md.

| file | bonneref section | bonneref inventory row |
| --- | --- | --- |
| `axial-joint.glb` | Owned (build123d, 2026-10-16) | `axial-joint.glb` / Inner tie rod / axial joint / housing O36 x 40, hex 32 AF x 12, stub O14 x 20, dome cap O30, rod O14, total 270, stud flats 12 AF x 10, tip chamfer, lock-ring groove / 4144 / 36.95 × 36.0 × 270.0 |
| `brake-drum.glb` | Owned (build123d, 2026-10-16) | `brake-drum.glb` / Drum brake drum / O230, bore O203, depth 60, centre bore O57, 4x O13 on PCD 100, 8 ribs / 2846 / 230.0 × 230.0 × 60.0 |
| `brake-hose.glb` | Owned (build123d, 2026-10-16) | `brake-hose.glb` / Flexible brake hose / O10, ~300 long S-curve, hex+stub, banjo eye O20x8, clip plate / 3254 / 322.0 × 58.56 × 35.57 |
| `brake-shoe.glb` | Owned (build123d, 2026-10-16) | `brake-shoe.glb` / Drum brake shoe / R101.5, 120 deg, web 4, rim 40 wide, lining 5, 3 web holes, anchor notch / 1520 / 149.19 × 101.5 × 40.0 |
| `bulb-h7.glb` | Owned (build123d, 2026-10-16) | `bulb-h7.glb` / H7 halogen bulb, PX26d / glass O11.5 x ~23, flange 26x20x1, total 55 / 2960 / 26.0 × 20.0 × 55.01 |
| `bulb-p21w.glb` | Owned (build123d, 2026-10-16) | `bulb-p21w.glb` / P21W BA15s bayonet bulb / envelope O26, cap O15 x 18, 2 pins O2, total 50 / 3923 / 26.0 × 26.0 × 49.97 |
| `bulb-w5w.glb` | Owned (build123d, 2026-10-16) | `bulb-w5w.glb` / W5W W2.1x9.5d wedge bulb / tube O10, wedge 9.5x3x9, total 25 / 1786 / 10.0 × 10.0 × 24.9 |
| `coolant-temperature-sensor.glb` | Inventory (measured 2026-10-15) | `coolant-temperature-sensor.glb` / `CLTTempSensor` / 88 × 39 × 82 / 3258 |
| `drive-shaft.glb` | Inventory (measured 2026-10-15) | `drive-shaft.glb` / `DriveShaft` / 995 × 207 × 103 / 11302 |
| `engine-mount.glb` | Inventory (measured 2026-10-15) | `engine-mount.glb` / `EngineMount` / 104 × 58 × 83 / 3772 |
| `expansion-tank.glb` | Owned (build123d, 2026-10-16) | `expansion-tank.glb` / Coolant expansion tank / 180x110x140, neck O40 + cap, 2 spigots O16x25, slotted tab, MIN/MAX ribs / 3944 / 242.0 × 111.0 × 166.0 |
| `front-lower-control-arm.glb` | Inventory (measured 2026-10-15) | `front-lower-control-arm.glb` / `Front_LCA_1` / 351 × 92 × 424 / 15418 |
| `front-shock-absorber.glb` | Inventory (measured 2026-10-15) | `front-shock-absorber.glb` / `Shock_body` / 79 × 525 × 79 / 8480 |
| `gasket-flat.glb` | Owned (build123d, 2026-10-16) | `gasket-flat.glb` / Perimeter gasket / ~300x180 loop, 10 wide, 1.5 thick, 14 bolt-hole ears / 5216 / 312.0 × 192.0 × 1.5 |
| `gasket-head.glb` | Owned (build123d, 2026-10-16) | `gasket-head.glb` / 4-cyl head gasket / 420x120x1.5, 4 bores O80 + 0.5 fire rings, 10 bolt holes O12, 6 oval holes / 5172 / 420.0 × 120.0 × 2.0 |
| `gasket-manifold.glb` | Owned (build123d, 2026-10-16) | `gasket-manifold.glb` / 4-port manifold gasket / 380x50x1.5, 4 ports 35x28 + beads, 8 bolt holes / 5228 / 380.0 × 50.0 × 2.0 |
| `idler-pulley.glb` | Inventory (measured 2026-10-15) | `idler-pulley.glb` / `Idler` / 52 × 31 × 52 / 712 |
| `ignition-coil-pack.glb` | Inventory (measured 2026-10-15) | `ignition-coil-pack.glb` / `CoilPack` / 167 × 86 × 133 / 11780 |
| `injector.glb` | Inventory (measured 2026-10-15) | `injector.glb` / `Injector` / 27 × 78 × 37 / 1972 |
| `jerrycan-5l.glb` | Owned (build123d, 2026-10-16) | `jerrycan-5l.glb` / 5 L oil container / 190x115x280, R15 corners, neck O42, cap O46x22, 1 mm label recess / 1926 / 190.0 × 115.0 × 280.0 |
| `lower-control-arm-bushing.glb` | Inventory (measured 2026-10-15) | `lower-control-arm-bushing.glb` / `LCA_Bushing` / 52 × 68 × 52 / 1856 |
| `lower-radiator-hose.glb` | Inventory (measured 2026-10-15) | `lower-radiator-hose.glb` / `CoolingHose_Bottom` / 163 × 265 × 380 / 3648 |
| `outer-tie-rod-end.glb` | Inventory (measured 2026-10-15) | `outer-tie-rod-end.glb` / `OuterTieRodEnd` / 41 × 113 × 74 / 5246 |
| `oxygen-sensor.glb` | Inventory (measured 2026-10-15) | `oxygen-sensor.glb` / `o2Sensor` / 24 × 88 × 25 / 3714 |
| `radiator.glb` | Inventory (measured 2026-10-15) | `radiator.glb` / `Radiator` / 653 × 85 × 454 / 5411 |
| `rear-brake-pad.glb` | Inventory (measured 2026-10-15) | `rear-brake-pad.glb` / `RearPad` / 102 × 12 × 43 / 628 |
| `rear-shock-absorber.glb` | Inventory (measured 2026-10-15) | `rear-shock-absorber.glb` / `RearShockBody` / 79 × 447 × 79 / 8472 |
| `rear-wheel-bearing.glb` | Inventory (measured 2026-10-15) | `rear-wheel-bearing.glb` / `RearWheelBearing` / 74 × 40 × 74 / 896 |
| `shaft-seal.glb` | Owned (build123d, 2026-10-16) | `shaft-seal.glb` / Radial lip seal / OD52, ID35, width 8, metal case + rubber lip / 1848 / 52.0 × 52.0 × 8.0 |
| `starter.glb` | Inventory (measured 2026-10-15) | `starter.glb` / `Starter` / 132 × 193 × 117 / 10571 |
| `alternator.glb` | Inventory (measured 2026-10-15) | `alternator.glb` / `Alternator_Body` / 164 × 138 × 165 / 32561 |
| `sway-bar-end-link.glb` | Inventory (measured 2026-10-15) | `sway-bar-end-link.glb` / `EndLinkBody` / 20 × 62 × 19 / 1440 |
| `tensioner-pulley.glb` | Inventory (measured 2026-10-15) | `tensioner-pulley.glb` / `Tensioner` / 77 × 31 × 74 / 1480 |
| `throwout-bearing.glb` | Inventory (measured 2026-10-15) | `throwout-bearing.glb` / `ThrowoutBearing` / 68 × 31 × 65 / 1836 |
| `timing-chain-kit.glb` | Third party (2026-10-16) | `timing-chain-kit.glb` / https://grabcad.com/library/engine-timing-chain-motion-simulation (unverified) / unknown (not in zip) / unknown (GrabCAD terms, unverified) / private prototype only, replace before public / 11380 / 36.0 × 355.1 × 111.2 |
| `valve-cover-gasket.glb` | Inventory (measured 2026-10-15) | `valve-cover-gasket.glb` / `BP_VC_Gasket` / 252 × 35 × 423 / 8730 |
| `wiper-blade.glb` | Owned (build123d, 2026-10-16) | `wiper-blade.glb` / Flat beam wiper blade / 500 long, beam 15x10 tapering, lip, adaptor 30x15x15, end caps / 228 / 512.0 × 15.0 × 44.64 |
