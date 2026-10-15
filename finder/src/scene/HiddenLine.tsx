import { useFrame } from '@react-three/fiber';
import { useMemo, useRef } from 'react';
import { BackSide, BufferGeometry, Color, EdgesGeometry, ShaderMaterial } from 'three';
import { DRAFT_BLUE, PAPER } from './palette';

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

const OUTLINE_PX = 1.1;

interface Props {
  geometry: BufferGeometry;
  threshold?: number;
  ghost?: boolean;
}

export function HiddenLine({ geometry, threshold = 25, ghost = false }: Props) {
  const edges = useMemo(() => new EdgesGeometry(geometry, threshold), [geometry, threshold]);
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
        <meshBasicMaterial color={PAPER} polygonOffset polygonOffsetFactor={1} polygonOffsetUnits={1} />
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
      <lineSegments geometry={edges} renderOrder={2}>
        <lineBasicMaterial color={DRAFT_BLUE} transparent={ghost} opacity={opacity} />
      </lineSegments>
    </group>
  );
}
