import { PerspectiveCamera, View } from '@react-three/drei';
import { Canvas } from '@react-three/fiber';
import { createRef, Suspense, type MutableRefObject } from 'react';
import { createRoot } from 'react-dom/client';
import { MESHES, meshFile } from '../src/meshes';
import { PICTO_IDS } from '../src/picto';
import { FOV, HOME, fitDistance, orbitPosition } from '../src/scene/camera';
import { GltfPart, MESH_SPAN } from '../src/scene/MeshPart';

// Dev-only contact sheet (never in the build: vite builds index.html only). Every shipped mesh in the
// app's hidden-line style, one View per cell over a single WebGL context.
const files = [...MESHES.map(meshFile), 'oil-filter.glb', 'front-brake-web.glb', 'rear-brake-web.glb'];
const base = `${import.meta.env.BASE_URL}parts/`;
const pictos = import.meta.glob<string>('../src/pictos/*.svg', { query: '?raw', import: 'default', eager: true });
const cells = files.map(() => createRef<HTMLDivElement>());
const distance = fitDistance(MESH_SPAN * 0.62, FOV, 1.2, 0.85);
const position = orbitPosition([0, 0, 0], distance, HOME.azimuth, HOME.polar) as [number, number, number];

function Sheet() {
  return (
    <>
      <h1>PLANCHE — {files.length} PIÈCES (public/parts)</h1>
      <div className="grid">
        {files.map((f, i) => (
          <figure key={f}>
            <div className="cell" ref={cells[i]} />
            <figcaption>{f}</figcaption>
          </figure>
        ))}
      </div>
      <h1>PICTOGRAMMES — {PICTO_IDS.length} FAMILLES (src/pictos)</h1>
      <div className="grid">
        {PICTO_IDS.map((id) => (
          <figure key={id}>
            <div className="cell picto" dangerouslySetInnerHTML={{ __html: pictos[`../src/pictos/${id}.svg`] ?? '' }} />
            <figcaption>{id}</figcaption>
          </figure>
        ))}
      </div>
      <Canvas flat dpr={[1, 2]} gl={{ antialias: true, alpha: true }} eventSource={document.body} style={{ position: 'fixed', inset: 0 }}>
        {files.map((f, i) => (
          <View key={f} track={cells[i] as MutableRefObject<HTMLElement>}>
            <PerspectiveCamera makeDefault fov={FOV} near={distance / 50} far={distance * 50} position={position} onUpdate={(c) => c.lookAt(0, 0, 0)} />
            <Suspense fallback={null}>
              <GltfPart url={`${base}${f}`} ghost={false} />
            </Suspense>
          </View>
        ))}
      </Canvas>
    </>
  );
}

createRoot(document.getElementById('root')!).render(<Sheet />);
