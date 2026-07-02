import { useEffect, useMemo } from 'react';
import * as THREE from 'three';

// The stageVariant 'mat' floor (see stageVariant.ts, the shipped default) — a procedural cutting-mat: no
// textures, the grid + heavier 5-cell lines + faint 45° guides + a self-healing-mat noise variation are
// all computed in GLSL from the plane's own local xy, injected via onBeforeCompile — the same recipe blueprintBuild's
// patchHolo uses to extend a stock material rather than hand-roll lighting. Grafting onto
// MeshStandardMaterial (lit by the existing SceneLights) means tonemapping/colorspace are the stock PBR
// output path; we only nudge diffuseColor before that, so nothing here can out-bloom the HUD.
//
// Ground reads graphite/blue-grey — NEVER green (green is the health signal; PRODUCT.md "light is
// meaning"). Line brightness falls off radially from the podium so the centre reads etched and the far
// edge reads as workbench, not a glowing floor.
const MAT_BASE = '#1c2026';

function patchCuttingMatGrid(mat: THREE.MeshStandardMaterial): void {
  mat.onBeforeCompile = (shader) => {
    shader.vertexShader = `varying vec2 vMatXY;\n${shader.vertexShader}`.replace(
      '#include <begin_vertex>',
      '#include <begin_vertex>\n\tvMatXY = position.xy;',
    );
    shader.fragmentShader = `varying vec2 vMatXY;\n${shader.fragmentShader}`.replace(
      '#include <color_fragment>',
      `#include <color_fragment>
      {
        vec2 gp = vMatXY;
        float distC = length(gp);
        float falloff = 1.0 - smoothstep(3.4, 15.0, distC); // brighter near the podium, near-invisible past it

        // fine grid (0.4 units) + a heavier line every 5 cells (2.0 units)
        vec2 fineMod = mod(gp + 0.2, 0.4) - 0.2;
        float fineLine = 1.0 - smoothstep(0.0, 0.014, min(abs(fineMod.x), abs(fineMod.y)));
        vec2 heavyMod = mod(gp + 1.0, 2.0) - 1.0;
        float heavyLine = 1.0 - smoothstep(0.0, 0.024, min(abs(heavyMod.x), abs(heavyMod.y)));

        // very faint 45° guide lines, wider spacing
        float d1 = (gp.x + gp.y) * 0.7071;
        float d2 = (gp.x - gp.y) * 0.7071;
        float d1Mod = mod(d1 + 1.5, 3.0) - 1.5;
        float d2Mod = mod(d2 + 1.5, 3.0) - 1.5;
        float diagLine = 1.0 - smoothstep(0.0, 0.02, min(abs(d1Mod), abs(d2Mod)));

        float grid = max(fineLine * 0.32, heavyLine * 0.62);
        grid = max(grid, diagLine * 0.1);

        // cheap hash noise — self-healing-mat surface variation, so it reads as material not vector
        float n = fract(sin(dot(gp, vec2(12.9898, 78.233))) * 43758.5453);

        diffuseColor.rgb += (n - 0.5) * 0.015;
        diffuseColor.rgb += vec3(0.4, 0.46, 0.55) * grid * falloff * 0.4;
      }`,
    );
  };
  mat.needsUpdate = true;
}

const MAT_SIZE = 34; // world units — well past the camera frustum, so the plane's own edge is never seen

export function CuttingMat() {
  const material = useMemo(() => {
    const mat = new THREE.MeshStandardMaterial({ color: MAT_BASE, roughness: 0.88, metalness: 0.04 });
    patchCuttingMatGrid(mat);
    return mat;
  }, []);
  useEffect(() => () => material.dispose(), [material]);

  return (
    <mesh position={[0, -0.15, 0]} rotation={[-Math.PI / 2, 0, 0]} material={material} receiveShadow>
      <planeGeometry args={[MAT_SIZE, MAT_SIZE]} />
    </mesh>
  );
}
