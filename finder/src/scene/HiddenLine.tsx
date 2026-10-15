import { Line } from '@react-three/drei';
import { useFrame } from '@react-three/fiber';
import { useMemo, useRef } from 'react';
import { BackSide, BufferGeometry, Color, EdgesGeometry, ShaderMaterial } from 'three';
import { DRAFT_BLUE, LINE_VISIBLE, PAPER } from './palette';
import { completeRings, type Seg } from './rings';

// Hidden-line drafting: a paper-coloured fill pushed back by polygonOffset occludes the far edges,
// EdgesGeometry draws the creases, and an inverted hull (back faces grown along the normal by a
// constant number of screen pixels) draws the silhouettes that creases miss on curved surfaces.
const hullVertex = /* glsl */ `
  uniform float uThickness;
  void main() {
    vec3 p = position + normalize(normal) * uThickness;
    gl_Position = projectionMatrix * modelViewMatrix * vec4(p, 1.0);
  }
`;
const hullFragment = /* glsl */ `
  uniform vec3 uColor;
  uniform float uOpacity;
  void main() { gl_FragColor = vec4(uColor, uOpacity); }
`;

// The hull grows outward only, so its visible width equals OUTLINE_PX: matched to the crease lines.
const OUTLINE_PX = LINE_VISIBLE;

interface Props {
  geometry: BufferGeometry;
  threshold?: number;
  ghost?: boolean;
  rings?: boolean;
}

export function HiddenLine({ geometry, threshold = 25, ghost = false, rings = false }: Props) {
  const edges = useMemo(() => {
    const pos = new EdgesGeometry(geometry, threshold).getAttribute('position');
    const segs: Seg[] = [];
    for (let i = 0; i + 1 < pos.count; i += 2) segs.push([[pos.getX(i), pos.getY(i), pos.getZ(i)], [pos.getX(i + 1), pos.getY(i + 1), pos.getZ(i + 1)]]);
    return (rings ? completeRings(segs) : segs).flat();
  }, [geometry, threshold, rings]);
  const hull = useRef<ShaderMaterial>(null);
  const uniforms = useMemo(
    () => ({ uThickness: { value: 0.5 }, uColor: { value: new Color(DRAFT_BLUE) }, uOpacity: { value: 1 } }),
    [],
  );
  const opacity = ghost ? 0.28 : 1;
  uniforms.uOpacity.value = opacity;

  useFrame(({ camera }) => {
    if (hull.current && 'zoom' in camera) hull.current.uniforms.uThickness!.value = OUTLINE_PX / (camera as { zoom: number }).zoom;
  });

  return (
    <group>
      <mesh geometry={geometry} renderOrder={0}>
        <meshBasicMaterial color={PAPER} polygonOffset polygonOffsetFactor={4} polygonOffsetUnits={12} />
      </mesh>
      <mesh geometry={geometry} renderOrder={1}>
        <shaderMaterial
          ref={hull}
          side={BackSide}
          transparent={ghost}
          uniforms={uniforms}
          vertexShader={hullVertex}
          fragmentShader={hullFragment}
        />
      </mesh>
      {edges.length > 0 && (
        <Line points={edges} segments color={DRAFT_BLUE} lineWidth={LINE_VISIBLE} transparent={ghost} opacity={opacity} renderOrder={2} polygonOffset polygonOffsetFactor={-4} polygonOffsetUnits={-12} />
      )}
    </group>
  );
}
