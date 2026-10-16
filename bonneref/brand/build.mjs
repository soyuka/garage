import { createRequire } from 'node:module'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const { BRAND_TOOLS, BRAND_FONT } = process.env
if (!BRAND_TOOLS || !BRAND_FONT) {
  console.error('Set BRAND_TOOLS (dir with node_modules/opentype.js) and BRAND_FONT (IBMPlexSans-Medium.ttf). See brand/README.md.')
  process.exit(1)
}
const opentype = createRequire(join(BRAND_TOOLS, 'x.js'))('opentype.js')
const buf = readFileSync(BRAND_FONT)
const font = opentype.parse(buf.buffer.slice(buf.byteOffset, buf.byteOffset + buf.byteLength))

const BLUE = '#2b59c3'
const GRAPHITE = '#222428'
const PAPER = '#ffffff'
const out = join(here, '..', 'public', 'brand')

// Profile, mm, y = 0 at the base (bonneref/src/showcase.json, Mazda 6 2.0, cluster c1).
const P = { sealIn: 31, gasketR: 35.5, bandR: 38, bodyR: 37.5, topR: 29.5, fillet: 8, yGasket: 4, yBand: 10, yShoulder: 85, yTop: 93 }
const ELEV = (20 * Math.PI) / 180
const SIN = Math.sin(ELEV)
const COS = Math.cos(ELEV)
const BORE_R = 19.05 / 2
const HOLE_R = 3
const PITCH_R = 22
const HOLES = Array.from({ length: 8 }, (_, i) => (i * Math.PI) / 4 + Math.PI / 8)
const THREAD_Y = [5.6, 7.4, 9.2]

const n = (v) => +v.toFixed(1)
const f = (...p) => p.map(n).join(' ')

// Side views of the part laid out in pixels: k px per mm, axis at x = ox, v (screen height in mm) = 0 at y = oy.
function mark(k, ox, oy) {
  const X = (x) => ox + x * k
  const Y = (v) => oy - v * k
  const vc = (y) => y * COS
  const arcFront = (r, v0) => `M${f(X(-r), Y(v0))}A${f(r * k, r * SIN * k)} 0 0 0 ${f(X(r), Y(v0))}`
  const arcFull = (r, v0) => `${arcFront(r, v0)}A${f(r * k, r * SIN * k)} 0 0 0 ${f(X(-r), Y(v0))}`

  const { gasketR, bandR, bodyR, topR, fillet: R, yGasket, yBand, yShoulder, yTop } = P

  // A: elevation
  const a = [
    `M${f(X(-gasketR), Y(0))}H${n(X(gasketR))}V${n(Y(yGasket))}H${n(X(bandR))}V${n(Y(yBand))}H${n(X(bodyR))}V${n(Y(yShoulder))}`,
    `A${f(R * k, R * k)} 0 0 0 ${f(X(topR), Y(yTop))}H${n(X(-topR))}`,
    `A${f(R * k, R * k)} 0 0 0 ${f(X(-bodyR), Y(yShoulder))}V${n(Y(yBand))}H${n(X(-bandR))}V${n(Y(yGasket))}H${n(X(-gasketR))}Z`,
    `M${f(X(-gasketR), Y(yGasket))}H${n(X(gasketR))}`,
    `M${f(X(-bodyR), Y(yBand))}H${n(X(bodyR))}`,
  ].join('')

  // B: 3/4 view from below (elevation -20 deg), the base is what faces the viewer.
  // v = y cos(e) + z sin(e), z = depth toward the viewer; a circle at height y has its centre at y cos(e), ry = r sin(e).
  const sealIn = P.sealIn
  const proj = (x, y, z) => [X(x), Y(y * COS + z * SIN)]
  const ray = (x, y, z) => {
    const holes = HOLES.map((a) => [PITCH_R * Math.cos(a), PITCH_R * Math.sin(a)])
    for (let t = 0.3; y - SIN * t > -1e-9; t += 0.2) {
      const py = y - SIN * t
      const pz = z + COS * t
      const r = Math.hypot(x, pz)
      if (py > 0 && py < yGasket && r > sealIn && r < gasketR) return true
      if (py > yGasket && r < bandR) {
        const inBore = r < BORE_R
        const inHole = holes.some(([hx, hz]) => Math.hypot(x - hx, pz - hz) < HOLE_R)
        if (!inBore && !inHole) return true
      }
    }
    return false
  }
  // Visible runs of a horizontal circle, as elliptical arcs between bisected occlusion boundaries.
  const circle = (cx, cz, y, r) => {
    const pt = (th) => [cx + r * Math.cos(th), y, cz + r * Math.sin(th)]
    const vis = (th) => !ray(...pt(th))
    const N = 144
    const state = Array.from({ length: N }, (_, i) => vis((i / N) * 2 * Math.PI))
    const rx = r * k
    const ry = r * SIN * k
    const at = (th) => {
      const [px, py, pz] = pt(th)
      return proj(px, py, pz)
    }
    if (state.every(Boolean)) return `M${f(...at(0))}A${f(rx, ry)} 0 0 0 ${f(...at(Math.PI))}A${f(rx, ry)} 0 0 0 ${f(...at(2 * Math.PI))}`
    if (!state.some(Boolean)) return ''
    const edge = (lo, hi) => {
      let vlo = vis(lo)
      for (let i = 0; i < 24; i++) {
        const mid = (lo + hi) / 2
        if (vis(mid) === vlo) lo = mid
        else hi = mid
      }
      return (lo + hi) / 2
    }
    const start = state.findIndex((v, i) => v && !state[(i + N - 1) % N])
    let d = ''
    for (let j = 0; j < N; ) {
      const i = (start + j) % N
      if (!state[i]) {
        j++
        continue
      }
      let e = 0
      while (j + e < N && state[(start + j + e) % N]) e++
      const th0 = ((start + j) / N) * 2 * Math.PI
      const th1 = ((start + j + e - 1) / N) * 2 * Math.PI
      const a0 = j === 0 && state.every((v) => v) ? th0 : edge(th0 - (2 * Math.PI) / N, th0)
      const a1 = edge(th1, th1 + (2 * Math.PI) / N)
      const span = a1 - a0
      d += `M${f(...at(a0))}A${f(rx, ry)} 0 ${span > Math.PI ? 1 : 0} 0 ${f(...at(a1))}`
      j += e
    }
    return d
  }

  const env = []
  const steps = 32
  for (let i = 0; i <= steps; i++) {
    const th = (Math.PI / 2) * (i / steps)
    const phi = Math.atan(Math.sin(th) / Math.tan(ELEV))
    const r = topR + R * Math.cos(phi)
    const y = yShoulder + R * Math.sin(phi)
    env.push([r * Math.cos(th), y * COS + r * Math.sin(th) * SIN])
  }
  const crown = [...env.map(([x, v]) => [X(-x), Y(v)]), ...env.map(([x, v]) => [X(x), Y(v)]).reverse().slice(1)].map(([x, y]) => `${n(x)} ${n(y)}`)
  const silhouette =
    `M${f(X(-bandR), Y(vc(yGasket)))}V${n(Y(vc(yBand)))}H${n(X(-bodyR))}V${n(Y(vc(yShoulder)))}L${crown.join('L')}V${n(Y(vc(yBand)))}H${n(X(bandR))}V${n(Y(vc(yGasket)))}` +
    `M${f(X(-gasketR), Y(0))}V${n(Y(vc(yGasket)))}M${f(X(gasketR), Y(0))}V${n(Y(vc(yGasket)))}`
  const major = [silhouette, circle(0, 0, 0, gasketR), circle(0, 0, 0, sealIn), circle(0, 0, yGasket, bandR), circle(0, 0, yBand, bandR)].join('')
  const minor = [
    circle(0, 0, yGasket, BORE_R),
    ...HOLES.map((al) => circle(PITCH_R * Math.cos(al), PITCH_R * Math.sin(al), yGasket, HOLE_R)),
    ...THREAD_Y.map((yy) => circle(0, 0, yy, BORE_R)),
  ].join('')
  const b = major
  const bMinor = minor

  const vmin = -gasketR * SIN
  const vmax = Math.max(...env.map(([, v]) => v))
  const axis = `M${f(X(0), Y(-4))}V${n(Y(yTop + 4))}`
  return { a, b, bMinor, axis, vmin, vmax, Y, X }
}

const CHROME = (css) => `<style>${css}</style>`
const L = '.l{fill:none;stroke:#2b59c3;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round;vector-effect:non-scaling-stroke}'
const D = '.d{fill:none;stroke:#2b59c3;stroke-width:1.1;stroke-linecap:round;vector-effect:non-scaling-stroke}'
const C = '.c{fill:none;stroke:#2b59c3;stroke-width:1.1;stroke-dasharray:10 2.5 2 2.5;vector-effect:non-scaling-stroke}'
const PEN = '.p{fill:none;stroke:#2b59c3;stroke-width:1;stroke-linecap:round;vector-effect:non-scaling-stroke;opacity:0}'

const CYCLE = 7
const t = (s) => +((s / CYCLE) * 100).toFixed(4)
const T0 = t(2.4)
const T1 = t(3.5)
const T2 = t(5.9)
const FADE = t(0.1)
const EASE = 'cubic-bezier(.645,.045,.355,1)'

function animation(D) {
  const E = -D
  const kf = (name, frames) =>
    `@keyframes ${name}{${frames.map(([p, v, e]) => `${p}%{transform:translateY(${n(v)}px)${e ? `;animation-timing-function:${e}` : ''}}`).join('')}}`
  const opac = `@keyframes po{0%,${T0}%{opacity:0}${+(T0 + FADE).toFixed(4)}%,${+(T1 - FADE).toFixed(4)}%{opacity:1}${T1}%,${T2}%{opacity:0}${+(T2 + FADE).toFixed(4)}%,${+(100 - FADE).toFixed(4)}%{opacity:1}100%{opacity:0}}`
  const frames = [
    kf('ka', [[0, 0], [T0, 0, EASE], [T1, D, 'linear'], [100, D]]),
    kf('kb', [[0, 0], [T2, 0, EASE], [100, D]]),
    kf('kc', [[0, 0], [T0, 0, EASE], [T1, D, 'linear'], [T2, D], [+(T2 + 0.0001).toFixed(4), 0], [100, 0]]),
    kf('kd', [[0, 0], [T2, 0], [+(T2 + 0.0001).toFixed(4), E, EASE], [100, 0]]),
    kf('kp', [[0, 0], [T0, 0, EASE], [T1, D, 'linear'], [+(T1 + 0.01).toFixed(4), 0], [T2, 0, EASE], [100, D]]),
    opac,
  ].join('')
  const anim = (name) => `animation:${name} ${CYCLE}s infinite linear`
  return (
    frames +
    `.ca{${anim('ka')}}.cb{${anim('kb')}}.cc{${anim('kc')}}.cd{${anim('kd')}}.p{animation:kp ${CYCLE}s infinite linear,po ${CYCLE}s infinite linear}` +
    `@media (prefers-reduced-motion:reduce){.ca,.cb,.cc,.cd,.p{animation:none}.p{display:none}.va{display:none}.vb{clip-path:none}}`
  )
}

function animatedMark(m) {
  const pb = m.Y(m.vmin) + 1.5
  const pt = m.Y(m.vmax) - 1.5
  const D = pt - pb
  const x0 = m.X(-44)
  const x1 = m.X(44)
  const W = 3000
  const BIG = 1000
  const rect = (cls, y, h) => `<rect class="${cls}" x="-1000" y="${n(y)}" width="${W}" height="${h}"/>`
  const defs =
    `<clipPath id="ca">${rect('ca', pb - BIG, BIG)}${rect('cb', pb, BIG)}</clipPath>` +
    `<clipPath id="cb">${rect('cc', pb, BIG)}${rect('cd', pt - BIG, BIG)}</clipPath>`
  const body =
    `<g class="va" clip-path="url(#ca)"><path class="l" d="${m.a}"/><path class="c" d="${m.axis}"/></g>` +
    `<g class="vb" clip-path="url(#cb)"><path class="l" d="${m.b}"/><path class="d" d="${m.bMinor}"/></g>` +
    `<path class="p" d="M${n(x0)} ${n(pb)}H${n(x1)}"/>`
  return { defs, body, css: animation(D) }
}

const svg = (w, h, inner, { title }) =>
  `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${n(w)} ${n(h)}" width="${n(w)}" height="${n(h)}" role="img" aria-label="${title}"><title>${title}</title>${inner}</svg>\n`

mkdirSync(out, { recursive: true })

// mark.svg and mark-static.svg
{
  const S = 120
  const k = 1
  const vmin = -P.gasketR * SIN
  const probe = mark(k, S / 2, 0)
  const pad = (S - (probe.vmax - vmin) * k) / 2
  const m = mark(k, S / 2, pad + probe.vmax * k)
  const am = animatedMark(m)
  writeFileSync(join(out, 'mark.svg'), svg(S, S, `${CHROME(L + D + C + PEN + am.css)}<defs>${am.defs}</defs>${am.body}`, { title: 'bonneref' }))
  writeFileSync(join(out, 'mark-static.svg'), svg(S, S, `${CHROME(L + D)}<path class="l" d="${m.b}"/><path class="d" d="${m.bMinor}"/>`, { title: 'bonneref' }))
}

// logo.svg
{
  const fontSize = 36
  const cap = (font.tables.os2.sCapHeight / font.unitsPerEm) * fontSize
  const k = (1.9 * cap) / P.yTop
  const markW = 2 * P.bandR * k
  const gap = 0.35 * markW
  const textX = markW + gap
  const text = font.getPath('bonneref', textX, 0, fontSize)
  const tb = text.getBoundingBox()
  const baseV = -P.gasketR * SIN
  const m = mark(k, markW / 2, baseV * k)
  const top = Math.min(m.Y(m.vmax), tb.y1)
  const bottom = Math.max(m.Y(m.vmin), tb.y2)
  const W0 = tb.x2
  const pad = 0.04 * W0
  const W = W0 + 2 * pad
  const H = bottom - top + 2 * pad
  const dx = pad
  const dy = pad - top
  const m2 = mark(k, dx + markW / 2, dy + baseV * k)
  const am = animatedMark(m2)
  const txt = font.getPath('bonneref', dx + textX, dy, fontSize).toPathData(1)
  const inner =
    `${CHROME(L + D + C + PEN + am.css)}<defs>${am.defs}</defs><rect width="${n(W)}" height="${n(H)}" fill="${PAPER}"/>` +
    `${am.body}<path fill="${GRAPHITE}" d="${txt}"/>`
  writeFileSync(join(out, 'logo.svg'), svg(W, H, inner, { title: 'bonneref' }))
}
