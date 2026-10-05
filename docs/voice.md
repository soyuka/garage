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

**Rule 0 — digits-only part references are not spoken (2026-10-05).** Spelled out, « volant 623 3150 33
+ 415 0463 10 » or « Valeo 836538 » is a string of millions read as noise; the card keeps them, the voice
says « volant » and « volant Valeo ». `Verbalizer::dropNumericReferences()` runs first (before digit
grouping glues « 623 315 » into one figure) and drops a digit run that is neither a quantity (a unit or
€ beside it) nor a number: space-separated groups that are not a thousands grouping, or a single run of
5+ digits. The `+`, `/`, `,` or « et » joining two dropped references goes with them. Kept on purpose:
« 15 000 km », « 250347 km », « 12500 € », « 2026 ». Known cost: a bare 5+ digit figure with no unit
(« à 250347 », a postcode) is dropped too. Hyphenated or lettered references (`B593-18-840A`,
`J1313021`) are unchanged by this rule. This is the one rule that removes words rather than rewriting
them.

**Approximations and ranges (2026-10-05).** « ~ » / « ≈ » before a figure reads « environ » (en
"about"), and an unspaced en dash between two figures is a range: « 90–100 € » → « quatre-vingt-dix à
cent euros », « €90–100 » → "ninety to one hundred euros". A spaced dash stays a clause break and a
hyphen stays a reference (`5W-30`). `Verbalizer::rewriteApproximationsAndRanges()`, run right before
currency.

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
  **Correction, 2026-09-11:** the manual toggle is removed — audition is now always on, no persisted
  on/off preference; only the mic still force-mutes it while listening.
- **The AudioContext trap applies unchanged** (§ "The AudioContext trap"): a piloted session produces
  no gesture in the browser, so playback must be unlocked from the operator's first real interaction
  and then asserted, not assumed. `<audio>.play()` is under the same autoplay policy as WebAudio.
- **Bursts must queue, not overlap.** A pilot fires four intents in 300 ms; four overlapping voices is
  noise. One utterance at a time, a short queue, and **drop stale lines** rather than narrate a
  backlog — the burst-coalescing rule, applied to the ear.
- **Prefetch while the turn works.** The synth can start the moment the frame lands, so the audio is
  ready before the operator has finished reading the card. No `sleep`, no gating — the card renders
  when it renders (hard rule 1).

## Measured — the « euh » between sentences, and the model shortlist (2026-09-11)

The operator reported a hesitation sound between spoken lines, cut off oddly. It is not the scheduler
and not a hallucinated filler: it is the **trailing « … »**, obeyed as a prosody cue. Measured on the
shipped voice and model (Claire, `eleven_flash_v2_5`, `pcm_24000`), with the clip's own words timed by
running it back through Scribe:

| line | clip | last word ends | trail-off |
|---|---|---|---|
| « Je regarde ça… » | 1.16 s | 0.68 s | **0.48 s** (41 % of the clip) |
| « ouverture du dossier… » | 1.21 s | 0.90 s | 0.31 s |
| « lecture des données véhicule… » | 1.49 s | 1.26 s | 0.23 s |
| « Vidange enregistrée sur la Mazda 6. » | 2.14 s | 1.94 s | 0.20 s |
| « Bonjour Soyuka, sur quelle voiture travaille-t-on ? » | 2.41 s | 2.20 s | 0.21 s |

The same words with a full stop instead: « Je m'en occupe… » 1.30 s → « Je m'en occupe. » 0.93 s, the
spoken part identical in both (Scribe transcribes both as `Je m'en occupe`, last word ending at 0.70 s).
So the ellipsis buys **~370 ms of breathy sustain and nothing else** — the words are identical, ending
at the same timestamp — and 28 French lines end in one: every acknowledgement, every step.

`Verbalizer` rewrote « … » to a full stop for about an hour on 2026-09-11, and then **put it back**.
What changed in between is the seam (below). The sustain was never the defect: it was followed by a
~190 ms hole and a cold start, and *that* is what reads as a hesitation. Joined flush to the next line
it is a bridge, which is the one thing a trailing ellipsis is for — and the catalogues already spell
the distinction, since only lines that are NOT the end carry one. The ASCII « ... » still collapses to
a full stop, as it always did.

**The residue, stated plainly:** a line carrying « … » that turns out to be last — a turn ending on a
step, or a successor whose body arrives late — trails into silence exactly as before.

### The model shortlist, same line, same voice

| model | TTFB | whole body | audio |
|---|---|---|---|
| `eleven_flash_v2_5` (shipped) | **165 ms** | 261 ms | 3.20 s |
| `eleven_turbo_v2_5` | 227 ms | 270 ms | 3.44 s |
| `eleven_multilingual_v2` | **1 158 ms** | 1 201 ms | 3.53 s |

`multilingual_v2` is **7× slower to first byte** — on a ticker line that is the difference between a
voice that answers the click and one that arrives after the screen has moved on, which is the whole
reason flash was chosen (§ the first-chunk measurement above). `turbo_v2_5` costs **+62 ms** and is the
one worth an ear: flash is the distilled low-latency model, turbo the balanced one. Nothing here
measures *quality* — that needs a listen, not a stopwatch.

### Runtime decision (2026-09-12)

The runtime moved from `eleven_turbo_v2_5` / Claire to `eleven_v3` / Alexandre, prioritising quality
over the expected extra latency ([models](https://elevenlabs.io/docs/models),
[streaming](https://elevenlabs.io/docs/api-reference/text-to-speech/stream)). `language_code: fr` and
24 kHz PCM streaming are unchanged. Alexandre uses Eleven v3's stock **Natural** delivery with
`stability: 0.5`, rather than Creative: part references and quantities need repeatable diction
([prompting](https://elevenlabs.io/docs/best-practices/prompting/eleven-v3),
[playground settings](https://elevenlabs.io/docs/product-guides/voices/voice-settings)). The audio cache
already keys `backend|model|voice|locale|text`, so this switch cannot replay a Claire/turbo clip.

### Cadence correction — +5 % for Alexandre (2026-09-12)

The preceding wording described a Natural delivery with `stability: 0.5`, but the API request did not
actually carry a `voice_settings` object. That made a speed decision in ElevenCreative a dashboard
preference rather than a reproducible runtime setting. ElevenLabs documents API speed as **0.7–1.2**,
with **1.0** neutral ([speed control](https://elevenlabs.io/docs/help-center/product/core-capabilities/text-to-speech/can-i-change-the-pace-of-the-voice)).

`ELEVENLABS_SPEED` is now a typed, validated API setting: repository/test default **1.0**, live local
demo **1.05**. It is sent per request as `voice_settings.speed`, and is part of the speech-cache key;
a clip made at another cadence cannot be replayed. **This +5 % decision still needs an ear audit** on
normal answers and part references before it becomes a stage default; the vendor warns that extremes
can affect quality.

## The gapless seam (2026-09-11)

The « euh » report had a second half the punctuation fix does not answer: *"would've been fine if we
didn't cut it and joined the next sentence right"*. Correct on the cause — the sound was never cut
(`speaker.ts` schedules every chunk and the last source plays to its end; only a dismissal, a mute or
the mic truncates a line). What the operator heard was the **hole after it**: the next line was promoted
only once the previous had finished *playing*, so each seam cost a React round-trip plus
`primeSeconds` + `leadInSeconds` (≈190 ms) of fresh priming, on top of the model's trail-off.

What makes a seam possible at all is the delivery rate: **a 3.2 s line's whole body arrives in 261 ms**,
~12× faster than realtime (the table above). So ~0.3 s in, every buffer is placed and the exact end time
is known, leaving a ~3 s window in which to schedule the successor flush against it. The prefetch layer
already had the next body in flight.

The change, in one line each:

- `speak()` takes a `startAt` and reports `placed` — the audio-clock time of its last sample, settled
  when the body is fully scheduled, long before it is heard.
- `ChunkClock` accepts that time as its origin. It already clamped to `max(now, planned)`, so a body
  that arrives too late simply degrades to the old cold start rather than scheduling into the past.
- `voice.ts` `speaking` became `onAir: Utterance[]` — `[0]` audible, `[1]` committed behind it, cap 2.
  The split matters beyond the seam: `speakingId` (the notice read-dwell) must follow what is being
  HEARD, not what is merely scheduled, or a card would start clearing while it was still being read.
- `next(…, canChain)` promotes the successor while the first line is still audible, once it is placed.

**Not verified by ear.** Every rule is unit-tested (9 new cases in `voice.test.ts`) and the arithmetic
is above, but no one has listened to a seam yet — that needs a browser and a running turn.

## The room bed (2026-09-11) — built, auditioned, REMOVED

**The bed is not in the product.** It was built, heard, found annoying and reverted the same day
(`CHOREOGRAPHY.md` § "The bed that was built and removed"). What follows is kept as the **generated-SFX
recipe**, which is reusable and was paid for: prompt wording, the cost model, the measurements worth
taking and the mastering steps. Read it as method, not as a description of anything that ships.

It reversed `CHOREOGRAPHY.md` § "a continuous ambient bed". Generated, not sourced —
`POST /v1/sound-generation`, **200 credits per generation, flat**, against a pool where TTS costs
1 credit per character. That price is why generation is a **build-time** step with a committed asset
and never a runtime call: one bed generated per session of an app would outspend the entire voice.

### The inversion that made generation the right tool here

`FUTURE.md` § 9 rejected generated SFX for the `échéance` icon because eleven generations came back
*"consistently noisy — room tone and texture baked in"*. For a caution signal peaking at gain `0.14`
that is a defect. For a room bed it is **the specification**. The earlier negative result is evidence
*for* generation in this application; it is not overturned, it is re-read.

### The prompts, both of them

The first attempt is worth keeping because it failed in an instructive way:

> Quiet empty machine workshop at night. Distant low HVAC hum, faint electrical transformer buzz, soft
> air movement. No voices, no footsteps, no tools, no impacts, no music. Continuous even room tone,
> unchanging.

→ **−70.6 dBFS RMS**, i.e. silence. `quiet`, `distant`, `faint`, `soft` and five negations produced
almost nothing; at that level 16-bit leaves ~4 bits of real resolution, so it could not be rescued by
gain. **Describe presence, not absence** — the model spends its budget on the adjectives, and negations
buy nothing.

> Steady industrial room tone inside a large empty machine workshop. Deep ventilation rumble, warm
> electrical transformer hum, full air-handling drone. Present, rich, close, continuous and unchanging
> throughout.

→ usable on the first try. `duration_seconds: 30` (the maximum), `loop: true`, `prompt_influence: 0.5`,
`model_id: eleven_text_to_sound_v2`, `output_format=pcm_24000`.

### Measured, on the generation and then on the committed asset

`pcm_24000` is **interleaved stereo** — 2 880 000 bytes for 30 s, not the 60 s of mono it looks like.

| | generation | after mastering | why it matters |
|---|---|---|---|
| RMS / peak | −32.6 / −21.6 dBFS | −13.9 / −3.0 dBFS | the asset is normalised so loudness lives in `tokens/audition.ts` and nowhere else |
| energy < 300 Hz | 98 % | — | leaves the 500–3000 Hz alerting band (HFDS §7.2.7.2) to the one signal |
| energy 500–3000 Hz | 0.6 % | — | it is spectrally incapable of masking `échéance` |
| at 660 / 1980 Hz | 0.004 % / 0.001 % | — | the two `échéance` partials specifically |
| energy > 3 kHz | 0.0 % | — | which is what licenses the 8 kHz resample below |
| peakiness (max − median short-term RMS) | 2.5 dB | 2.5 dB | **eventless** — no transient that could read as a thing happening |
| loop splice vs mean sample delta | 0.0031 vs 0.00077 (**4× larger**) | 0.0015 vs 0.019 (**12× smaller**) | `loop: true` alone did not give a seamless seam; the crossfade did |

### Mastering, and why each step is defensible rather than taste

`L/R` correlation came back **0.9998** — the generation is dual-mono in all but name, so collapsing to
mono discards nothing. Measured energy above 3 kHz is **0.0 %**, so a 3.6 kHz lowpass and decimation to
**8 kHz** is inaudible rather than a compromise. Together: **2.8 MB → 461 KiB**, which matters because
"no external CDN assets, the page stays self-contained" makes asset weight a real budget. A 500 ms
equal-power crossfade folds the tail into the head and the file is trimmed by that much — 29.50 s, not
30. The script is `scratchpad/master.py` in the session that built it; the steps are listed here
because the scratchpad will not survive.

**The 8 kHz decision is tied to THIS asset.** It preserves everything this file contains and forecloses
ever adding air or texture to it. A future bed with content above 4 kHz needs its own rate, not this one.

### Unresolved

- **Licensing.** `FUTURE.md` § 9 flagged it and it is **still open**: free-tier ElevenLabs output may be
  attribution-bound or non-commercial, and this asset is committed into the product. Check the live
  terms before the file ships — not from memory.
- **`TTS_MONTHLY_CHAR_BUDGET` is declared and never read.** `api/.env:181` sets 9000; the only mention
  in code is a comment in `ElevenLabsTranscriptionClient.php:25`, and `useVoice.ts` documents a
  "204 over budget" path that nothing implements. At 200 credits a generation, nothing stands between
  an afternoon of prompt iteration and the month's voice.
- **Verified by ear, and that is how it died.** Every number above was measured and every rule was
  unit-tested before anyone listened. The operator's verdict on first hearing — *"more annoying than
  anything else"* — is not visible in any of them. The transferable lesson: for anything audible,
  audition BEFORE building the surrounding machinery, not after.

## Transition cues — provenance and licensing (2026-09-11)

**Superseded after listening, 2026-09-11.** The five-file set below remains as the first
implementation's provenance. Runtime now uses the approved generated spatial palette under
`dashboard/public/audio/spatial-2026-09-11/`. Each runtime WAV is an unchanged copy of its listening
master in `docs/audio-candidates-2026-09-11/`; exact prompts, raw hashes, request parameters and ffmpeg
filters remain in the adjacent metadata JSON.

| runtime | accepted source | SHA-256 |
|---|---|---|
| `select.wav` | `select-spatial.wav` | `36327d0d2a2af24dd36d9da9e45d2898cea8a1c1a9a6d4517bba4ff0dcba23ee` |
| `panel.wav` | `panel-spatial-v3.wav` | `124a8a24dd6853deb63653f3647cbe39fdd32218b5939d531dff28823a668564` |
| `reveal.wav` | `reveal-spatial.wav` (accepted recording preserved) | `3f6a1fe0c383b7a88d3ed23d28f81e1dae485f1d85e4fe4185b55c5c206b8183` |
| `card.wav` | `card-spatial.wav` | `dcf5d0e5bca45ebd54f67aee73b9e0d5b132ae2b77591ff14e9ad6ab47132511` |
| `dismiss.wav` | `dismiss-spatial.wav` | `a578894bdc019793ebd18d5bc7b9076ea8e1032efa7396bc7ed924825f29302d` |
| `complete.wav` | `complete-spatial.wav` | `e67b782f0b4c56dd2d063bfad1459b9c0e9b335f5dcec05c4f0eca8656fd040b` |
| `blocked.wav` | `blocked-spatial.wav` | `534c58cd5772797404f77749822151ac1351dfa15cfb9109a5c124bdf138912e` |
| `mic.wav` | `mic-spatial.wav` | `a0b821162c42bc9dee6bba82f05fa2c728baa902aae8cf83c4d1e981ce63e1ff` |
| `working.wav` | `working-spatial.wav` | `fe61f27ae66407dfbb4b3802cd530b15fb29b49d4fa6342cbc6591f9ff168869` |
| `opening.wav` | `opening-spatial.wav` | `b7e9a4925acae16de8816476d1df56bd182b446e437067edf9fd6328407ec073` |

All ten use ElevenLabs `eleven_text_to_sound_v2`, `prompt_influence: 0.5`, spatial low-mid briefs and
a 3.5 kHz listening-master low-pass; the loop alone uses `loop: true`. Masters are never boosted and
are capped at −12 dBFS, with selection and dismissal deliberately quieter. Runtime maximum gain 0.50
makes the loudest possible peak about `10^(−12/20) × 0.50 = 0.126`, below the legacy signal ceiling
`0.14`; speech ducking lowers that to about `0.032`. This is a headroom fact, not perceived loudness.

Five assets under `dashboard/public/audio/`, all 24 kHz stereo, normalised to −3 dBFS peak so loudness
is decided in `tokens/audition.ts` and nowhere else. **Provenance differs per file and that is the
licensing-relevant fact**, so it is recorded here rather than inferred later:

| file | source | origin | length |
|---|---|---|---|
| `panel.wav` | **ElevenLabs library** (SCICmpt, "A very quiet futuris…") | downloaded from the web UI | 2.00 s |
| `reveal.wav` | **ElevenLabs library** (SCICmpt, "Cyberpunk hacking in…") | downloaded from the web UI | 3.08 s |
| `working.wav` | **ElevenLabs library** (SCICmpt, "A clean futuristic i…") | downloaded from the web UI | 0.68 s loop |
| `select.wav` | **generated**, `POST /v1/sound-generation` | "Crisp futuristic interface selection confirm…" | 0.48 s |
| `card.wav` | **generated**, `POST /v1/sound-generation` | "Soft sci-fi notification chime…" | 1.00 s |

### Why the library beat generation, measured

Three generation attempts at a "data processing" loop all came back as **continuous low drones** —
74–100 % of energy below 300 Hz and peakiness ~2 dB — regardless of prompt. The model follows the first
adjective and discards the rest: every prompt led with "hum"/"low"/"warm" produced pure bass, and the
two that led with "shimmer"/"crisp" produced pure treble. Nothing generated came back full-spectrum or
event-shaped.

The library files are the opposite on every axis that mattered:

| | generated attempts | library `working` | library `quiet` / `cyber` |
|---|---|---|---|
| energy < 300 Hz | 74–100 % | **0 %** | 11 % / 69 % |
| peakiness | ~2 dB (flat drone) | 1.7 dB | **21.8 / 40.4 dB** (sparse events) |
| loop seam | needed a crossfade | **already 36× below mean sample delta** | n/a |

`working.wav` needed **no** splice treatment — its endpoints match as downloaded. The one-shots got
3 ms edges only. Library downloads also cost **0 credits** against 200-per-generation (or 40/second for
custom durations — see the cost note below).

### Licensing — UNRESOLVED, and now live

Two generated files are committed, so `FUTURE.md` § 9's gate is no longer hypothetical. What is known:

- ElevenLabs prohibits distributing sound effects "as standalone files … or sound libraries".
  Embedding them in your own application is permitted. Five `.wav` files served at predictable
  `/audio/*.wav` URLs sit closer to that line than an opaque bundle does. The repo is **private**
  (`soyuka/mon-parc-auto.fr`), which helps; the deployed URLs are not.
- **Attribution depends on plan tier**: required (`elevenlabs.io`) on free, not on paid. The key in
  `api/.env.local` **401s on `GET /v1/user/subscription`** — it is scoped or revoked — so the tier
  could not be read programmatically. Check it in the web app before these ship; it decides whether a
  credit line is owed in the UI.

### Cost, and a correction

Billing is **200 credits per generation flat, OR 40 credits/second when `duration_seconds` is set**.
Both room-bed generations passed `duration_seconds: 30`, so that experiment may have cost up to
**2400 credits**, not the 400 first reported. The practical rule: for short assets, passing an explicit
short duration is far cheaper than the flat rate (a 0.9 s cue ≈ 36 credits); for anything near 30 s,
omitting it is. Balance could not be verified — see the 401 above.
