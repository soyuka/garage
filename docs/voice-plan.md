# Plan — the Atelier speaks (ElevenLabs first, provider-swappable)

Status: **phases 1, 3, 4 built (2026-08-16); phase 6 done (2026-09-04, via pocket-tts); phases 2, 5, 7 open.** Research and the route comparison live in
`docs/voice.md`. The provider decision was reaffirmed after the free-tier measurement below:
ElevenLabs now, a paid plan later for the French voice.

## Decisions locked

| | |
|---|---|
| Provider now | **ElevenLabs**, `eleven_flash_v2_5` (~75 ms, 0.5 credit/char). Streams `pcm_24000` wrapped as WAV since 2026-09-05, so it rides the same chunked path as pocket-tts (`api/src/Speech/ElevenLabsSpeechClient.php`, `api/src/Speech/Wav.php`) — first sound after headers, not after the whole body |
| Second backend | **pocket-tts**, local, shipped 2026-09-04 (`TTS_BACKEND=pockettts`) — it, not Google, is what proved the seam. Google Chirp 3 HD remains unimplemented |
| What is spoken | the existing **`toast {tone, message}`** frame. **No new wire verb** |
| Default state | **muted**, one persisted toggle |
| Where the key lives | `api/` only. The browser never sees it |

**Why no new verb.** A `say()` tool would let the agent speak something that is not on screen, which the
audition binding rule forbids (*nothing is audio-only*). Speaking the toast means the notice card
(shipped 2026-08-16) is always the visual half of the same event.

They can still diverge in *coverage* — the stack holds 3, the voice queue 2, and stale lines are
dropped unread — but never in *content*: the spoken string IS the printed string. The card is
authoritative, the voice is best-effort, and nothing is ever only spoken.

### Voice selection (2026-09-06)

`api/` now exposes the operator's four French ElevenLabs voices instead of pinning one via
`ELEVENLABS_VOICE_ID` alone. `SpeechClient::voices(Locale $locale): list<SpeechVoice>` (first
element = the backend's default) sits beside `stream()`, which grew a third `?string $voice`
parameter — `null` keeps meaning "the backend's own default".

- `ElevenLabsSpeechClient::voices()` calls `GET /v1/voices`, keeps only voices whose
  `labels.language` matches $locale (or that carry no `language` label at all), trims the name at
  the first `-`/`–` for the label, and sorts the configured default first. Any HTTP/transport/JSON
  failure falls back to `[new SpeechVoice($voiceId, 'ElevenLabs')]` — never a broken `/tts/voices`.
- `PocketTtsSpeechClient::voices()` answers one voice, id `'default'`, labelled from a new
  `POCKET_TTS_VOICE_LABEL` env var (`Estelle` in `api/.env`, matching the compose stack's voice);
  `stream()` ignores the requested voice — one model per locale endpoint, nothing to pick per call.
- `CachingSpeechClient` folds the REQUESTED voice (not just the configured default) into the audio
  cache key, and caches `voices()` itself for 1h per locale as `.voices.<locale>.json` in the same
  `var/tts` directory.
- `SpeechController`: `POST /tts` accepts an optional `voice` id in the JSON body — a value absent
  from `voices($locale)` is silently ignored (falls back to null/default), never a 400. New
  `GET /tts/voices` returns `{"id","label"}[]` for the request's locale, `Cache-Control: private,
  max-age=3600`; `Vary: Accept-Language` is NOT set manually — `set_locale_from_accept_language`
  already makes Symfony's `ResponseListener` append it, and setting it again duplicates the header.
- The dashboard picker itself is a separate `dashboard/` workstream, built on this surface.

## Prerequisite (blocks phase 1 only)

An ElevenLabs account + API key, and one chosen French voice id. Everything below is buildable and
testable without it (the tests mock the HTTP client); only the live listen needs it.

**Measured against the live API 2026-08-16 — a free key cannot speak French.** Not "sounds worse":
cannot. `GET /v2/voices` on the free tier returns exactly **21 voices, every one `language: en`**
(american/british/australian). Pushing French text through them via `eleven_flash_v2_5` produces
English-accented French — listened to, rejected. Every French voice lives in the shared library, and
the library is closed to free keys **at the API**, categorically:

```
POST /v1/text-to-speech/{any library voice}
402 paid_plan_required — "Free users cannot use library voices via the API."
```

That 402 is returned even for voices whose `GET /v1/shared-voices` record says
`free_users_allowed: true` — that flag governs the **web app**, not the API (verified against
`gP52hjM48hyNoVCbizxO`, `NEjemlRxgWmL5ZGJetsB`, `6FXyooAOTqUK8m2HWm32`, `APeMLYgzzS2PWgyn5j9V`; all
402). Adding a library voice to *My Voices* does not lift it either — it is still a library voice to
the API. Free-tier cloning is also off (`can_use_instant_voice_cloning: false`), so there is no way
to manufacture one. **Speaking French therefore costs a paid plan, or it is Piper.** Phase 1 builds
and tests fully either way; only the voice id at the end is gated.

Scopes the key needs: `text_to_speech`, `voices_read` (a voice id must be *verified*, never guessed),
`sound_generation` (phase 7). `models_read` / `user_read` are optional — the model is pinned in env
and the budget guard is a local counter by design (phase 2), not a quota read.

```
# api/.env.local
TTS_BACKEND=elevenlabs
ELEVENLABS_API_KEY=…
ELEVENLABS_VOICE_ID=…          # picked by listening, see phase 1 step 4
ELEVENLABS_MODEL=eleven_flash_v2_5
TTS_MONTHLY_CHAR_BUDGET=9000   # free tier is 10k credits ≈ 20k chars at 0.5/char; stay under
```

## Architecture

```
toast frame ──Mercure──▶ dashboard ──POST /tts {text}──▶ Symfony ──▶ SelectableSpeechClient
                              │                              │              ├─ ElevenLabsSpeechClient
                              │                              │              └─ GoogleSpeechClient (later)
                              │                              ├─ disk cache (sha256 → mp3)
                              ▼                              └─ monthly char budget guard
                        <audio> playback queue
```

Mirrors the **existing, proven** provider seam in `api/src/Agent/WebSearch/` — do not invent a second
shape: `WebSearchClient` (interface) + `SelectableWebSearchClient` (runtime router over a
`!service_locator`) + `WebSearchBackend` (env-backed enum), wired at `config/services.yaml:104-112`.
The locator matters: only the selected backend is instantiated, so the unused provider never demands
an API key.

## Phase 1 — the `api/` seam and endpoint ✅ BUILT 2026-08-16

Shipped as described, `510 tests, 2387 assertions` green. What landed:

| | |
|---|---|
| `api/src/Speech/SpeechClient.php` | the interface |
| `api/src/Speech/SpeechBackend.php` | enum `elevenlabs` / `google` |
| `api/src/Speech/SelectableSpeechClient.php` | the `!service_locator` router |
| `api/src/Speech/ElevenLabsSpeechClient.php` | the vendor call |
| `api/src/Speech/SpeechUnavailable.php` | the domain exception |
| `api/src/Controller/SpeechController.php` | `POST /tts` |
| `api/config/services.yaml:113-129` | the locator + backend enum env |
| `api/config/packages/security.yaml:31,52` | firewall `^/(garage\|agent\|tts)` + `IS_AUTHENTICATED_FULLY` |
| `api/tests/Functional/SpeechTest.php` | 7 cases |
| `api/tests/Mock/RecordingSpeechHttpClient.php` | the vendor transport double |

**One deviation from the plan, deliberate.** The plan said "owner-gated via `OwnerContext` exactly
like `DownloadMaintenanceAttachmentController`". It is **authentication**-gated instead, at
`access_control`. A bill download is owner-scoped because it reads *a stored record belonging to
someone*; `/tts` reads nothing and returns a pure function of its input, so there is no owner to scope
to — the thing being protected is the API key and the credits, which authentication already protects.
`OwnerContext` also could not have produced the 401 the plan asks for: the test env binds it a
`$fallback` owner, so an anonymous request resolves to `test-user` and the gate would never fire.
`OwnerContext` becomes genuinely necessary at phase 2, where the budget is counted **per owner**.

A second test beyond the plan's five: a vendor 429 must return **503 with an empty body**, never a
500 — the plan required it in prose ("never let a vendor failure 500 the dashboard") without listing
a case for it.

Original plan, for reference — test-first, `AppTestCase` + `MockHttpClient`; no test ever calls the
real vendor.

1. `tests/Functional/SpeechTest.php` — failing first:
   - anonymous `POST /tts` → **401** (an open TTS proxy is someone else's credits being spent)
   - authed with a fake OIDC token (`FakeOidcToken`) → **200**, `content-type: audio/mpeg`, body = the
     mocked bytes
   - the outgoing request carries `xi-api-key`, the configured voice id and `eleven_flash_v2_5`
   - empty / whitespace text → **400**, no vendor call
   - text over a hard cap (say 400 chars) → **400** (a toast is one line; a paragraph is a bug upstream)
2. `src/Speech/SpeechClient.php` — `synthesize(string $text): string` (mp3 bytes) + `SpeechBackend`
   enum (`elevenlabs`, `google`) + `SelectableSpeechClient` router. Copy the WebSearch docblock
   discipline: say *why* the seam exists, name the drop-in procedure.
3. `src/Speech/ElevenLabsSpeechClient.php` — `POST https://api.elevenlabs.io/v1/text-to-speech/{voice}`
   with `model_id`, `output_format=mp3_22050_32` (small, plenty for a one-liner), `xi-api-key` header.
   Map 401/429 to a domain exception; **never** let a vendor failure 500 the dashboard.
4. `src/Controller/SpeechController.php` — plain `#[Route('/tts', methods:['POST'])]`, owner-gated via
   `OwnerContext` exactly like `DownloadMaintenanceAttachmentController` gates a bill. Returns the
   bytes with a long `Cache-Control` (the text is the cache key, so the URL is stable).
5. `config/services.yaml` — the `!service_locator` block, `$backend: '%env(enum:App\Speech\SpeechBackend:TTS_BACKEND)%'`.
   `.env` gets the defaults (`TTS_BACKEND=elevenlabs`, empty key).
6. **Voice pick:** browse the **Voice Library filtered to the `conversational` use case** (that tag is
   the read we want — the Atelier answers a person standing next to a car, it does not narrate an
   audiobook), keeping French-capable voices. **Add the chosen ones to the account first** — a library
   voice only gets a usable `voice_id` in `GET /v1/voices` once it is in *My Voices*. Then render the
   same sentence through 3–4 of them, listen, put the winner in `.env.local`. Never guess a voice id.

Reminder from `CLAUDE.md`: after touching tool/agent code, `rm -rf var/cache` **inside** the php and
worker containers and restart both, or the worker keeps serving the old container.

## Phase 2 — do not burn credits twice for the same sentence

- **Disk cache** keyed `sha256(backend|model|voice|locale|text)` under `/app/var/tts` (already an anonymous
  volume; ephemeral is fine — a cache miss costs 60 characters). Hit → no vendor call at all. The
  Atelier repeats itself constantly (« Vidange enregistrée », « J'ai pris la Mazda ») so the hit rate
  should be high, and the cache is what makes the free tier last. **`locale` is in the key** (added
  2026-08-16 with fr/en): the same sentence read as French and as English is two different audios, and
  a key without it would serve one for the other. `/tts` also answers `Vary: Accept-Language` for the
  same reason at the HTTP layer. **Path is namespaced per environment**, `var/tts/%kernel.environment%`
  (fixed 2026-09-06): a single shared path let the functional suite's fake voice list ("Claire"/"Louis")
  leak into the live cache and get served back to the operator by the next real `GET /tts/voices`.
- **Monthly char budget** (`TTS_MONTHLY_CHAR_BUDGET`) counted in a cache pool keyed by `Y-m`. Over
  budget → **204 No Content**, not an error: the card is already on screen, so silence is a degraded
  mode, never a broken one. Log it once per month, not per call.
- Test both: a second identical request makes **zero** vendor calls; over-budget returns 204 and makes
  zero vendor calls.

## Phase 3 — `dashboard/` playback (pure logic first) ✅ BUILT 2026-08-16

`dashboard/src/noyau/voice.ts` (pure, 8 unit tests in `voice.test.ts`) + `useVoice.ts` (the impure
edge). Built as specified; the three questions the plan left open were ruled on:

- **`vu` cancels its own utterance.** The card list, not an event stream, is the queue's input, so a
  dismissed card takes its voice with it. Audio outliving its card would be audio-only.
- **The caps differ on purpose** (`NOTICE_CAP` 3 vs voice queue 2). The plan claimed card and voice
  "cannot drift"; they can, and now do. The card is authoritative and the voice is best-effort — that
  is the honest version of the same guarantee.
- **No `aria-live`, and muted by default is the accessibility answer**, not an oversight: speech is a
  third rendering of a sentence a screen reader already announces. Turning it on is the operator
  asking for it twice.

One deviation: the fetch does NOT use `authFetch`, which bounces to the login flow on 401 — a garnish
feature must never seize the session.

Original plan:

- `src/noyau/voice.ts` — **pure, unit-tested**, no DOM:
  - `enqueue(state, notice)` / `next(state)` — one utterance at a time, queue capped at **2**
  - **stale drop**: a line older than ~10 s is dropped unread (the burst rule, applied to the ear —
    a pilot firing four intents in 300 ms must not produce four overlapping voices, nor a backlog
    narrated a minute late)
  - dedup: the same text already spoken this turn is not re-spoken (mirrors the notice dedup)
- `src/noyau/useVoice.ts` — the impure edge: `POST /tts`, object URL, `<audio>`, `onended` → `next`.
  Prefetch on notice arrival so the audio is ready before the operator finishes reading the card.
  A 204 or any failure is **silent** — never a toast about a toast.
- **Autoplay unlock**: a piloted session produces **no
  gesture** in the browser, so playback must be unlocked from the operator's first real interaction
  and then asserted, not assumed. `<audio>.play()` is under the same policy as WebAudio.
- Tests: `voice.test.ts` covers cap, stale drop, dedup, ordering. The hook stays untested (no jsdom in
  this repo — component logic is tested by extracting it, which is why `voice.ts` is pure).

## Phase 4 — the toggle ✅ BUILT 2026-08-16

`useAudition()` in `useVoice.ts` (persisted `mpa.audition`, default off) + the speaker control in the
audition control, threaded through `SceneView.tsx` from
`Atelier.tsx` (it first lived in the à-décider inbox head; that inbox was removed 2026-11-09). Glyph carries state by SHAPE (waves vs cross), never colour alone.

Not done: the `stage/bin/…` preflight assertion that the stage profile ships muted.

Original plan:

- Ships **muted**. One persisted preference, `localStorage['mpa.audition']` — the key already
  reserved beside `mpa.recency` (verified: nothing reads it yet).
- Lived in the (since removed) **à-décider inbox** header.
  Manual reset + it is the volume control (MIL-STD-1472F §7.2.9.11 via the audition section).
- `stage/bin/…` preflight asserts muted for the stage profile, like the earcon rule.

## Phase 5 — docs (same commit as the code)

- `dashboard/CONTRACT.md`: unchanged — no new frame. Worth one sentence saying so explicitly.
- `docs/voice.md`: mark route A superseded by the hosted decision, keep Piper documented as the
  offline fallback (it is the only option that survives a dead conference wifi).

## Phase 6 — prove the swap ✅ DONE 2026-09-04, with pocket-tts rather than Google

The swap was proven by a **local** provider instead of Google: `TTS_BACKEND=pockettts` reaches a
Kyutai `pocket-tts serve` sidecar (`docs/voice.md` § PocketTTS measured). Cost, as the acceptance
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

## Phase 7 — the `échéance` icon (independent of phases 1–6, ship it first)

Not speech, and **not a palette**. One asset, replacing one existing sound.

The earlier audition design rejected recorded auditory icons on a single constraint — *"no external CDN
assets, the page stays self-contained"* — while conceding the evidence favours them (McNeer et al.
2018; Edworthy et al. 2014, 2017), and admits the current `échéance` is "honestly an abstract earcon"
only *because* synthesised oscillators cannot make a credible icon. An asset generated **once at build
time**, committed, and bundled by Vite is same-origin. It satisfies the constraint that produced the
rejection, so the rejection lapses.

**The property that makes this phase different from every other one here: there is no runtime
dependency.** `POST /v1/sound-generation` is run by a human on a workstation, the winning mp3 is
committed, and the deployed system never holds an ElevenLabs key, never makes a call, never spends a
credit, and sounds identical with the network unplugged. Phases 1–6 buy a spoken answer at the cost of
a vendor in the render path; phase 7 buys a better signal at no runtime cost at all. **That asymmetry
is why phase 7 should ship first and can ship alone.**

### What the sound must be — and the trap

Two rules in the repo constrain it harder than they first look, and they point the same way:

1. **Taste** — *"The reference is a well-instrumented machine, not
   a sci-fi movie UI: an aircraft PFD, a mixing desk, a hospital monitor."* A futuristic interface
   blip is the failure mode this names, and it also **forfeits the entire justification for the
   phase**: swapping an abstract earcon for a prettier abstract earcon gains nothing the evidence
   predicts. The gain comes from the sound being *of a thing*.
2. **Room-proof (HFDS §7.2.3.4)** — it must not resemble a sound the room already makes. This
   disqualifies the obvious worked example: a garage is full of ratchets, clicks and impacts,
   so *"a real ratchet"* is exactly wrong, and every transient-led icon with it.

The intersection is narrow and it is the same brief Villeneuve gave Territory Studio for Blade Runner
2049 — **"organic, abstract, optical and physical"**: a sustained,
resonant, physically-credible machine tone with **no strike transient**. Not a bell, not a timer, not
a chime — those read domestic. A coil, a servo settling, a bowed metal body. « Restraint reads as
real » is the other lesson drawn from it and it applies to the ear unchanged.

Everything else in the existing spec is inherited unchanged and is not up for renegotiation: two
pulses ~1.2 s apart, fires at most once per sweep, 500–3000 Hz, ≥15 ms attack, `setTargetAtTime`
release, peak gain `0.14` **never normalised**, scheduled against `ctx.currentTime`, muted by default.

### Steps

1. Generate candidates with `POST /v1/sound-generation` (`duration_seconds: 3.0`,
   `prompt_influence: 0.9`). Free tier is ample — 8 generations moved the character counter by ~120.
2. Listen on the target hardware, not headphones. The signal has to clear a bay with a compressor in it.
3. Post-process the winner to the spec above: trim to two pulses, strip any attack transient, peak at
   `0.14`, mono, and keep it **under ~30 KB** — it is bundle weight on every page load, sounded or not.
   Ship it as an mp3 asset under `dashboard/src/noyau/`, imported so Vite fingerprints it.
4. `audition.ts` gains a decode-once/`AudioBufferSourceNode` path beside the oscillator. **Keep the
   oscillator as the fallback** — a failed `decodeAudioData` must degrade to the current earcon, never
   to silence. That is the one place a caution signal may not fail closed.
5. Unit-test what is testable without an audio device: the buffer is fetched and decoded once and
   reused, the fallback fires on decode failure, muted state produces no `start()`.

### Must verify before committing the asset

**ElevenLabs free-tier output may not carry commercial-use rights, and may require attribution.**
Committing a generated asset into a product is precisely the case where that matters. Check the
current terms for the account's tier before the file lands in git. If free-tier output is
attribution-bound or non-commercial, the options are one paid month to generate a clean asset, or a
CC0 sample library. **Do not skip this and do not resolve it from memory** — it is a licensing
question about a file that ships.

### Acceptance

The `échéance` signal is a physical machine tone with no strike transient, ships muted, sounds
identical offline, adds under ~30 KB, and degrades to the current oscillator if decoding fails. The
taste rule above is satisfiable by someone else listening blind: asked what
the sound *is*, they name a machine, not a user interface.

## Risks

| Risk | Handling |
|---|---|
| conference wifi dies → every line silent | acceptable: the card is the payload, voice is the garnish. Piper stays documented as the offline fallback behind the same `/tts` |
| free tier exhausted mid-demo | budget guard + disk cache; over budget = silence, not an error |
| overlapping voices during a pilot burst | queue cap 2 + stale drop, unit-tested |
| API key leaking to the browser | the browser only ever calls same-origin `/tts`; the key is server-side and the endpoint is owner-gated |
| a talking dashboard on stage | ships muted; `mpa stage check` asserts it (§ "Not in the talk" stands) |

## Definition of done

Muted by default. Toggle on → the agent's next answer is spoken once, in French, while its card is on
screen. Same sentence twice costs one vendor call. Flipping one env var moves the whole thing to
Google. Every test green (`vendor/bin/phpunit`, `npm test`), no `sleep` anywhere in the render path.
