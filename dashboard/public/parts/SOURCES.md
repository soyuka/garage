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
`meshFit` in `assembly.ts`), corrected 2026-09-05 — see `dashboard/CHOREOGRAPHY.md` § The reveal.

### Not wired, by decision

- `engine-v6.glb` ("V6 car engine with engine [MoreThem]" by Blendoriano, CC-BY-4.0) — refused:
  vehicle-specific detail the data cannot back (a detailed V6 under a four-cylinder Mazda would be a
  lie; no vehicle carries an engine-layout datum). Removed from disk 2026-09-05. See
  `dashboard/CHOREOGRAPHY.md` § The reveal, correction 2026-09-04.
- `spark-plug-worn.glb` ("Old Rusty Spark Plug") — a WORN plug is a claim about the operator's plug the
  data does not hold. Removed from disk 2026-09-05.
- `fuel_filter` keeps its canister primitive — the oil-filter mesh is a different part.

### Licences

- **oil-filter.glb** — CC-BY-NC-4.0. Accepted by the owner 2026-09-04 ("personal app, any licence
  will do"); supersedes FUTURE.md's "drop oil-filter.glb".
- **spark-plug.glb / brake-disc.glb / battery.glb** — author + licence not captured; accepted on the
  same basis. Still to capture before any public ship.
