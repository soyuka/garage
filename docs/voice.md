# Voice — can the Atelier answer out loud? (research, 2026-08-16)

`dashboard/CHOREOGRAPHY.md` § Audition rejected speech in one table row, for four reasons. This
document re-opens exactly that row, because the rejection was written about a **signal** (an earcon
replacing "échéance dépassée") and the question now is a different one: the agent's **answers** —
« j'ai pris la Mazda, tu en as deux », « pas de filtre à air au catalogue pour ce moteur » — currently
land as more text on a screen that already has a lot of text.

The retained notice stack (CHOREOGRAPHY § "The notice stack") is the visual half of that fix. Voice
would be the audible half of the **same** `toast` frame — which is what keeps the binding rule intact:
*nothing is audio-only*, because every spoken line is a card on screen with a dismiss control.

## What the original rejection said, and what survives

| Original objection | Status after measuring |
|---|---|
| "`SpeechSynthesis` voices are OS-dependent, French quality is a lottery" | **Confirmed, harder than written.** On this machine Chrome returns **zero** voices: `speechSynthesis.getVoices()` → `[]` (Chrome 140, X11 Linux), because `speech-dispatcher` is not installed (`pacman -Qq`: no `speech-dispatcher`, no `espeak`). Not a lottery — a blank. Kills the Web Speech API as the transport |
| "bundling a voice breaks the self-contained page" | **Does not apply to a server path.** The no-CDN rule is about the *published page* fetching third-party hosts; the Atelier is served same-origin behind Caddy, so a `/tts` endpoint on the same origin breaks nothing. It DOES apply to the in-browser route (86 MB+ of model over the wire on first load) |
| "a talking dashboard over a conference PA is a catastrophe" | **Stands, unchanged.** Same argument as § "Not in the talk": the act is a presenter speaking while the UI answers visually. Ships muted, stage profile asserts muted |
| "auditory icons and speech beat abstract earcons on identification" (the reason it was *tempting*) | Unchanged — that evidence was always in favour |

## Measured on this machine (2026-08-16)

`piper-tts` is already installed (`/usr/bin/piper-tts`, `piper-tts-bin`), previously only with English
voices. Three French voices downloaded to `~/.local/share/piper-voices/`; each run is a **cold CLI
invocation**, so model load is included in the number — a warm server is strictly faster.

Sentence: « J'ai enregistré la vidange à cent quarante-deux mille kilomètres. Le filtre à huile n'est
pas au catalogue pour ce moteur. »

| Voice | Synth | Audio | RTF | Rate | Licence |
|---|---|---|---|---|---|
| `fr_FR-siwis-medium` (63 MB) | 0.68 s | 6.63 s | **0.103** | 22 050 Hz | CC-BY 4.0 |
| `fr_FR-upmc-medium` (77 MB) | 0.58 s | 6.59 s | **0.088** | 22 050 Hz | CC-BY-SA 4.0 |
| `fr_FR-tom-medium` (64 MB) | 1.08 s | 7.28 s | 0.148 | 44 100 Hz | see model card |

Short line (« J'ai pris la Mazda 6, tu en as deux au garage. », 3.4 s of audio): siwis 0.53–0.74 s,
upmc 0.38–0.40 s per cold run. Samples kept at `<scratchpad>/short-fr_FR-*.wav` — compare with
`aplay <file>` before picking a voice; the numbers do not tell you which one you can listen to for
ten minutes.

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
reader takes to glance at the card — so no holding of the text was needed and CHOREOGRAPHY hard rule 1
(*motion may overlap an arrival, never gate one*) is untouched. The client primes 160 ms of audio
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

### Pronunciation layer (2026-09-05)

The vendor reads the printed string exactly, symbols included, and real cards read badly aloud: a
spending summary chains « · » into one breathless run-on and reads « 250 347 km » as two numbers
rather than one. `SpeechController::__invoke()` now runs the outgoing text through
`App\Speech\Verbalizer` right before handing it to the backend — a deterministic pronunciation
dictionary, not a rephraser: it rewrites symbols, units, digit grouping and separators for the ear
and adds no content. The card on screen is untouched; only what reaches the vendor changes
(`dashboard/CHOREOGRAPHY.md` § "Audition — speech, for ANSWERS", corrected the same day: "the same
string" → "the same WORDS, pronounced").

Before/after, first golden (`tests/Unit/Speech/VerbalizerTest.php`):

```
Dernière vidange : 6 juillet 2026 à 250 347 km (MANNOL Energy 5W-30 + filtres, 53,20 €). Dépenses
2026 enregistrées : 53,20 € — les autres visites n'ont pas de montant saisi.
```

becomes

```
Dernière vidange : 6 juillet 2026 à 250347 kilomètres, MANNOL Energy 5W-30 plus filtres, 53,20
euros. Dépenses 2026 enregistrées : 53,20 euros, les autres visites n'ont pas de montant saisi.
```

**Rule 7 — numbers spelled out (2026-09-06).** Reading digits as digits is itself a pronunciation
bug: « 250347 kilomètres » still reads as a run of numerals, and « 53,20 euros » reads the currency
as a bare figure followed by a unit word. A final rule, applied LAST, spells out every standalone
number with `NumberFormatter::SPELLOUT` (`fr`/`en`) — a digit run is "standalone" when neither side
touches a letter, another digit, a hyphen, an apostrophe or a slash, which is exactly what leaves an
identifier such as `5W-30`, `246-H`, `J1313021`, `XB-301-ZB`, `16x650-8`, `A3/B3` untouched (the voice
spells those letter by letter, which is the right read for a reference). Currency is spelled inside
rule 2 itself — whole and cents spelled separately (« 53,20 € » → « cinquante-trois euros vingt »,
never « … virgule … »), with French agreement singular for both 0 and 1 (« zéro euro », « un euro »)
and English only for 1 (« zero euros », « one euro ») — so rule 7 never sees a currency digit at all.
Feminine fr units (`heure`, `minute`) get « une » directly in the units rule rather than letting rule
7 spell a gender-blind « un ». **Fixed 2026-09-06:** a bare integer 1 next to any other noun is left
as the digit rather than spelled — only the numeral one is gendered in French, so a generic « un »
was as likely to be wrong (« 1 visite » → « un visite », not the feminine « une visite ») as right; a
unit already handled by rule 3 (« 1 h » → « une heure ») never reaches this exception, and a decimal
(« 1,5 » → « un virgule cinq ») is unaffected since the gender question never arises before « virgule ».

The same golden above now reads:

```
Dernière vidange : six juillet deux mille vingt-six à deux cent cinquante mille trois cent
quarante-sept kilomètres, MANNOL Energy 5W-30 plus filtres, cinquante-trois euros vingt. Dépenses
deux mille vingt-six enregistrées : cinquante-trois euros vingt, les autres visites n'ont pas de
montant saisi.
```

## The three routes

| | How | First-load cost | Latency | Verdict |
|---|---|---|---|---|
| **A. Piper as a service** | `python3 -m piper.http_server -m fr_FR-siwis-medium.onnx` in its own compose container (upstream `OHF-Voice/piper1-gpl` ships the Dockerfile + HTTP API); Caddy proxies `/tts`; the dashboard fetches the wav and plays it | 0 in the browser | measured 0.4–0.7 s **cold**; warm server + short line → well under that | **Recommended.** Nothing to download in the browser, the binary is already on this machine, the model is a compose volume. GPLv3 applies to the *container*, not to our code |
| **B. In-browser neural TTS** | `kokoro-js` (Apache-2.0 model, WebGPU/WASM, q8 build ≈ 86 MB) or Supertonic 3 (ONNX-native, OpenRAIL-M) | 86 MB+ per browser, cached after | real-time-ish on WebGPU, machine-dependent | **Dead for French off-the-shelf** (verified 2026-08-16): `kokoro-js` exposes 28 voices, all `en-us`/`en-gb` — no `ff_siwis` — and its bundled `phonemizer` ships English-only espeak data, so `phonemize(…, 'fr')` throws `Invalid language identifier: "fr"`. Making it speak French means injecting the voice tensor **and** swapping in a full espeak-ng WASM build. The Kokoro *model* does have French; the JS stack does not. And that voice is grade **B-**, <11 h, the *same SIWIS corpus* piper renders locally at zero download |
| **C. Web Speech API** | `speechSynthesis.speak()` | 0 | instant | **Dead here** — zero voices on this box, and fixing it means installing speech-dispatcher + espeak-ng, i.e. a robot French. Would also be machine-dependent in the exact way the stage cannot rehearse |

Not considered further: anything with a paid API (ElevenLabs, OpenAI, Google), per the constraint.
Chatterbox-Turbo (~150 ms streaming, French among 9 languages) and PocketTTS (100 M, CPU-first,
French) are the 2026 quality leaders if voice ever outgrows Piper — both are heavier than a prototype
needs, and Chatterbox is a cloning model, which is a different set of questions.

## Hosted APIs — the free tiers are not all the same size

ElevenLabs was excluded on the "no paid API" constraint, not on merit (Flash v2.5, ~75 ms, best French
of anything here). Worth knowing before that constraint is applied to the whole category: **its free
tier is by far the smallest one**, and the big-cloud free tiers are permanent and ~100× larger.

| Service | Free tier | French | After the free tier | Catch |
|---|---|---|---|---|
| **Google Cloud TTS — Chirp 3 HD** | **1 M chars/month, no expiry** (4 M on Standard/WaveNet) | yes, `fr-FR-Chirp3-HD-*`, streaming supported | ~$30/1 M | GCP project + billing account on file; no SSML on streaming requests; no pause control for fr-FR |
| **Azure Speech (F0)** | **500 k chars/month, never expires** ≈ 10 h of audio | yes, neural fr-FR | $16/1 M standard neural, $22/1 M HD | Azure account; F0 has concurrency limits |
| **Amazon Polly** | 1 M chars/month neural (5 M standard) | yes, neural fr-FR | $16/1 M neural | **first 12 months only** — then it is simply a paid API |
| **ElevenLabs** | 10 000 credits/month ≈ **10 min of audio**; no commercial rights; attribution required | best-in-class | Starter $5/mo (30 k credits) for commercial rights | a rehearsal burns the month |

For a prototype that speaks one short line per agent answer, 500 k–1 M chars/month is effectively
unlimited: a toast is ~60 characters, so Azure's free tier is ~8 000 spoken answers **per month**.

Two costs that apply to every row and to no local option: an **API key that must stay server-side**
(so the browser never holds it — the dashboard asks our own `/tts`, which calls the vendor), and a
**network round trip per line**, which on conference wifi is the dependency workstream E cannot
rehearse. The garage text itself is innocuous, but it does leave the machine.

## If it gets built (design constraints already binding)

- **The wire needs no new verb.** Speak the `toast` message. The pilot already emits it, the notice
  card already shows it, and reusing it means the visual and the audible cannot drift apart.
  A separate `say()` tool would let an agent speak something that is not on screen — audio-only,
  forbidden.
- **Muted by default, one persisted toggle** (`mpa.audition` already reserved beside `mpa.recency`),
  and `mpa stage check` asserts muted. Same rule the earcon got.
- **The AudioContext trap applies unchanged** (§ "The AudioContext trap"): a piloted session produces
  no gesture in the browser, so playback must be unlocked from the operator's first real interaction
  and then asserted, not assumed. `<audio>.play()` is under the same autoplay policy as WebAudio.
- **Bursts must queue, not overlap.** A pilot fires four intents in 300 ms; four overlapping voices is
  noise. One utterance at a time, a short queue, and **drop stale lines** rather than narrate a
  backlog — the burst-coalescing rule, applied to the ear.
- **Prefetch while the turn works.** The synth can start the moment the frame lands, so the audio is
  ready before the operator has finished reading the card. No `sleep`, no gating — the card renders
  when it renders (hard rule 1).
