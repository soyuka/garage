# pocket-tts — archive (local Kyutai TTS backend, removed 2026-11-09)

## Summary

pocket-tts is Kyutai's CPU-first local text-to-speech (code MIT, weights CC-BY-4.0). It ran as a
`pocket-tts serve` sidecar (compose service `pocket-tts-fr`, `api/docker/pocket-tts/Dockerfile`),
reached through `PocketTtsSpeechClient` and selected with `TTS_BACKEND=pockettts`
(`POCKET_TTS_FR_URL`, `POCKET_TTS_EN_URL`, `POCKET_TTS_VOICE_LABEL`).

Dates: wired 2026-09-04; ElevenLabs Flash v2.5 (streamed PCM) chosen 2026-09-05; removed from the
code 2026-11-09. ElevenLabs is now the only TTS backend and the `TTS_BACKEND` selector is gone: with
no `ELEVENLABS_API_KEY` the server reports voice and dictation off and the dashboard hides them.

Key numbers (all measured on a Ryzen 7 PRO 6850U, CPU-only, `french_24l`):

- Warm resident `serve --quantize` is the only viable mode: warm + int8 is worth ~3x over the cold
  unquantized CLI (cold RTF 1.98-2.06 on the long line; warm wall 3.91 / 4.22 / 4.28 s for the same).
- First chunk latency, direct in the compose network: 243 ms (short line), 413 ms (medium line).
  Streaming RTF 0.77 (short) and 0.84 (medium); two concurrent lines ~1.1 each.
- Through `/tts` once streamed: first body bytes 0.535 s (short, cold), 0.208 s (medium, cold).
- Edge artefacts in the model's own output: onsets either 0.5-0.9 s of dead air or clipped
  mid-phoneme (speech at full amplitude in the first 20 ms, RMS 0.100-0.131), and 340-1080 ms of
  trailing silence on every line (0.3-1.1 s), which the queue plays before the next line.
- This box is ~4-6x slower than the vendor reference (distilled English model, M4).

Why it was dropped:

- Voice quality: the clipped onsets and the 0.3-1.1 s trailing silence are in the undistilled
  `french_24l` preview itself, not fixable by post-processing; ElevenLabs Flash was chosen for quality.
- `serve` binds one model per process (`--language`): a bilingual app needs one container per locale.
- An extra container to build and keep warm (model download, int8 quantisation).
- Once ElevenLabs was chosen the code path was dead weight, so it was removed along with the selector.

What it taught the seam (see the `SpeechClient` docblock, `api/src/Speech/SpeechClient.php`): it was
the swap proof of `docs/voice-plan.md` § Phase 6 and found two leaks. The return type was raw mp3
bytes, so the controller stamped `audio/mpeg` on a WAV body: fixed by `SpokenAudio` carrying its
content type. And it returned the whole body, so a streaming backend waited for its own last chunk
before the browser heard the first: fixed by returning `SpeechStream` (2026-09-05).

Resurrecting it: `PocketTtsSpeechClient`, `api/docker/pocket-tts/Dockerfile`, the `pocket-tts-fr`
compose service and the `SpeechBackend::PocketTts` case exist in git history before 2026-11-09.

The sections below are moved verbatim from `docs/voice.md` and `docs/voice-plan.md`; references to
`TTS_BACKEND`, `PocketTtsSpeechClient` and the compose service describe the code as it was.

---

# From docs/voice.md

## PocketTTS measured (2026-09-04) — viable, and now wired as a backend

Re-opened because the 2026-08-16 note below dismissed PocketTTS as "heavier than a prototype needs"
on a size claim, without measuring. Measured, it is usable — but *only* as a warm resident server,
which is the number that took two attempts to get right.

**Cold CLI invocation**, `french_24l`, unquantized — same protocol as the Piper table above, so these
compare directly. Each run reloads the model:

| line | wall | audio | RTF |
|---|---|---|---|
| long (2 sentences), cold incl. download | 76.70 s | 12.12 s | 6.33 |
| long | 12.61 s | 6.12 s | **2.06** |
| long | 13.24 s | 6.68 s | **1.98** |
| short | 9.93 s | 4.04 s | 2.46 |
| short | 8.61 s | 2.76 s | 3.12 |

That reads as "slower than realtime, unusable" — and it is the wrong measurement. It bundles a ~5 s
model load into every line, which the deployment does exactly once.

**Warm resident server** (`pocket-tts serve --language french_24l --quantize`), which is how it ships:

| line | wall |
|---|---|
| long (2 sentences) ×3 | 3.91 / 4.22 / 4.28 s |
| short ×3 | 1.48 / 1.94 / 2.01 s |
| ticker line (« Je consulte le catalogue de pièces… ») ×2 | 1.50 / 2.31 s |

Audio durations were not re-derived for the warm runs (the streamed WAV header carries a placeholder
length), so **no warm RTF is claimed here** — only the wall times, which are what the queue feels. On
the cold-run durations for the same strings (~6.4 s long, ~3.4 s short) these land comfortably under
realtime.

What the numbers turned out to depend on:

- **Warm + int8 is worth ~3×** over the cold unquantized path. `--quantize` is a `serve` flag.
- **`french_24l` is the undistilled preview** — the CLI's own help says *"bigger models, not distilled
  yet and here only as preview"*. The published ~6× realtime / ~200 ms figures are the **distilled
  English** model on an M4.
- **This box is ~4–6× slower than that reference.** The distilled English model measured RTF ~0.98
  cold-invoked here (7.1 s audio in 6.9 s) against ~0.167 claimed on an M4. So both the hardware gap
  and the preview-model weight are real, and they compound.
- **The `powersave` governor was not a factor.** `amd-pstate-epp` in `active` mode boosted to
  **4741 MHz** during the run; Tctl stayed at 37 °C. An idle frequency reading proves nothing here.
- **Output is 24 kHz mono WAV**, never mp3 — which is what forced `SpokenAudio` (see below).

Licence: code **MIT**, weights **CC-BY-4.0** (attribution obligation, satisfied by this file plus the
client docblock). French premade voice `estelle`. `serve` binds **one language per process**, so a
bilingual app needs one container per locale.

What it buys over the hosted route: no API key, no credit budget, no per-line trip off the machine,
and a demo that still speaks with the network unplugged — which retires the top entry in
`voice-plan.md` § Risks.

**Wired 2026-09-04** as `TTS_BACKEND=pockettts` (`api/src/Speech/PocketTtsSpeechClient.php`,
`api/docker/pocket-tts/Dockerfile`, compose service `pocket-tts-fr`). It doubled as the § Phase 6
swap proof, and it found exactly one leak in the seam: `SpeechClient` returned raw mp3 bytes and
`SpeechController` stamped `audio/mpeg` on them, so a WAV body was announced as mp3. Fixed by
`App\Speech\SpokenAudio` carrying the content type with the bytes. Everything else was the promised
one class + one enum case + one locator line.

## Streamed (2026-09-05) — the gap was the buffering, not the model

The operator's complaint: the spoken answer starts long after its card is on screen. Measured on the
same box (Ryzen 7 PRO 6850U, CPU-only, `french_24l --quantize`, warm `serve`, no CPU limit on the
container — `NanoCpus=0`, 16 cores visible):

**pocket-tts itself, inside the compose network** (`POST http://pocket-tts-fr:8000/tts`, chunk
arrival timestamps):

| line | headers | first audio bytes | steady state | complete |
| --- | --- | --- | --- | --- |
| short (4.2 s of audio) | 9 ms | **243 ms** | 3840 B = 80 ms of audio every ~60 ms | 3.23 s (RTF 0.77) |
| medium (10.0 s of audio) | 3 ms | **413 ms** | same | 8.36 s (RTF 0.84) |
| two short lines **concurrently** | — | 302 / 324 ms | — | 3.99 s for 3.64 s, 4.27 s for 3.96 s (**RTF ≈ 1.1 each**) |

**Through `/tts` as it was** (`SpeechController` → `CachingSpeechClient` → `PocketTtsSpeechClient`
buffering `getContent()` to stamp the WAV lengths), via Caddy, `curl -w`:

| line | TTFB | total |
| --- | --- | --- |
| short, cold | **3.18 s** | 3.18 s |
| short, cached | 29 ms | 30 ms |
| medium, cold | **10.4 s** | 10.4 s |
| medium, cached | 29 ms | 31 ms |

TTFB = total: the browser received nothing until the line was finished. The dashboard then wrapped the
whole body in a Blob for an `<audio>` element, so first sound ≈ TTFB + decode. Meanwhile
`RenderIntentTools::toast()` had ALSO enqueued a `PrewarmSpeech` job; the worker and the browser both
missed the cache within the same few tens of ms, so every first line was synthesized twice at once —
the concurrent row above — making the audible one ~30 % slower, not faster. On the in-app driver the
prewarm sat behind the running turn on the same single consumer and never ran until the turn ended.

**Dominant contributor: waiting for the whole body** (3.2–10.4 s), with the duplicate synthesis
stacked on top. Neither first-byte nor transfer nor decode mattered — headers were in 3–9 ms and a
cache hit came back whole in 30 ms.

**After** — `/tts` relays each chunk as it arrives (`StreamedResponse`, `ob_flush` + `flush` per
chunk because php.ini `output_buffering=4096` would otherwise hold a piece until the next one overflowed
it), the disk cache is written from the completed body once the stream has been consumed (the
header-stamped copy: `pack('V')` real sizes, verified on the cached file), the prewarm is removed, and
the browser decodes 16-bit PCM pieces and schedules each on the `AudioContext` clock
(`dashboard/src/noyau/pcm.ts`, `speaker.ts`; the non-WAV whole-body path remains only as the fallback
for a future backend that cannot speak WAV — ElevenLabs itself now streams PCM wrapped in the same
placeholder WAV header pocket-tts uses, see § Edge silence below and `docs/voice-plan.md`). Same
`curl -N` through Caddy/FrankenPHP, body-byte arrival timestamps:

| line | first body bytes | complete |
| --- | --- | --- |
| short, cold (1.7 s of audio) | **0.535 s** | 1.65 s |
| short, cached | 22 ms | 24 ms |
| medium, cold (8.5 s of audio) | **0.208 s** | 5.93 s |

First sound moves from "when the line is done" to ~0.2–0.5 s after the request — about the time a
reader takes to glance at the card — so no holding of the text was needed and the rule that
*motion may overlap an arrival, never gate one* is untouched. The client primes 160 ms of audio
(`tokens/audition.ts` `SPEECH.primeSeconds`) before the first sample so one late piece is absorbed.

Caddy needs nothing: its reverse proxy flushes immediately for a response without `Content-Length`,
and `encode` does not match `audio/wav`. `<audio>` could not have done this — no `MediaSource` codec
exists for WAV — which is why the element and its silent-frame blessing are gone.

## Edge silence, measured (2026-09-05) — why the voice feels approximate at the start and end of a line

Raw `POST /tts` on `pocket-tts-fr`, `french_24l --quantize`, warm. Silence = 20 ms windows with RMS
< 0.005 full scale.

| line | audio | gen | leading silence | trailing silence | RMS of first 20 ms |
| --- | --- | --- | --- | --- | --- |
| « Oui. » (4 ch) | 0.92 s | 0.53 s | 920 ms | 920 ms | 0.001 |
| « Je planifie l'intervention… » (27 ch) | 1.56 s | 0.90 s | **0 ms** | 340 ms | **0.106** |
| « Dernière vidange : 6 juillet 2026. » (34 ch) | 2.60 s | 1.48 s | **0 ms** | 640 ms | **0.100** |
| « Le filtre à huile HERTH+BUSS J1313021 est la référence retenue pour ce moteur. » (78 ch) | 7.72 s | 4.60 s | 520 ms | 760 ms | 0.001 |
| 183-char three-clause line | 10.84 s | 7.02 s | **0 ms** | 780 ms | **0.108** |
| « Je recherche les causes de surchauffe et les vérifications à effectuer sur la Mazda6. » (85 ch) | 4.36 s | 2.68 s | **0 ms** | 760 ms | **0.131** |

These artefacts are in the model's own output, not in `/tts` or the browser path. The onset is
erratic — either 0.5–0.9 s of dead air, or speech already at full amplitude in the very first 20 ms
(an onset clipped mid-phoneme, missing audio that no post-processing can restore). Every line then
carries 340–1080 ms of trailing silence, which the queue plays before the next line can start. The
model is Kyutai's undistilled `french_24l` preview.

Decision 2026-09-05: try ElevenLabs Flash v2.5 (streamed PCM) for voice quality; pocket-tts remains
the offline fallback. Follow-ups listed in `FUTURE.md`.

---

# From docs/voice-plan.md

## Phase 6 — prove the swap ✅ DONE 2026-09-04, with pocket-tts rather than Google

The swap was proven by a **local** provider instead of Google: `TTS_BACKEND=pockettts` reaches a
Kyutai `pocket-tts serve` sidecar (§ PocketTTS measured, above). Cost, as the acceptance
criterion demanded: one class (`PocketTtsSpeechClient`), one enum case, one locator line — plus one
genuine leak the exercise existed to find.

**The leak.** `SpeechClient::synthesize()` returned raw mp3 bytes and `SpeechController` hardcoded
`Content-Type: audio/mpeg`. pocket-tts emits 24 kHz mono WAV, so the second backend would have served
a WAV body labelled mp3. `App\Speech\SpokenAudio` now carries the format with the bytes. This is
exactly the "expensive after the code has grown ElevenLabs-shaped assumptions" the phase warned about,
caught while it was a three-line fix.

**What it changes for phase 2.** A local backend has no credits, so `TTS_MONTHLY_CHAR_BUDGET` and the
204-over-budget path only ever apply to a hosted backend. The disk cache is still worth having; the
budget guard is not, when `pockettts` is selected.

**One constraint the seam did not anticipate:** `pocket-tts serve` binds ONE model per process
(`--language`), so the client takes a locale→endpoint map and a locale with no server configured
raises `SpeechUnavailable` (→ 503 → silence) rather than reading English with French phonetics. The
compose stack ships the French container; the English twin is one more service plus one env var.

Google Chirp 3 HD remains unimplemented and is no longer the thing blocking confidence in the seam.

### Original plan, for reference

Add `GoogleSpeechClient` behind the same interface and flip `TTS_BACKEND=google` in a scratch env.
If that is more than *one class + one enum case + one locator line*, the seam is wrong and phase 1
needs fixing. This is the acceptance test for "we can switch later", and it is cheap **now** and
expensive after the code has grown ElevenLabs-shaped assumptions (voice id format, mp3 vs
LINEAR16/OGG_OPUS, header vs OAuth).

## Pocket-tts notes from the voice-selection section of docs/voice-plan.md (2026-09-06)

- `PocketTtsSpeechClient::voices()` answers one voice, id `'default'`, labelled from a new
  `POCKET_TTS_VOICE_LABEL` env var (`Estelle` in `api/.env`, matching the compose stack's voice);
  `stream()` ignores the requested voice — one model per locale endpoint, nothing to pick per call.

## The "Second backend" decision row of docs/voice-plan.md

| Second backend | **pocket-tts**, local, shipped 2026-09-04 (`TTS_BACKEND=pockettts`) — it, not Google, is what proved the seam. Google Chirp 3 HD remains unimplemented |
