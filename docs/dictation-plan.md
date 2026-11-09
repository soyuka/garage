# Plan — the Atelier listens (ElevenLabs Scribe, provider-swappable)

Status: **phases 0, 1 and 3 built (2026-09-11), bias vocabulary included.** `api/` green at 23 tests
/ 47 assertions (`TranscriptionTest` + `KeytermsTest`); the dashboard half green at 18 tests with
`tsc --noEmit` clean; `lint:container` clean. Phase 2 (the local sidecar) deferred.

**Phase 4 started, and the first real press failed (2026-09-11).** The control was push-to-talk and
recorded nothing: `getUserMedia` resolves behind a modal permission prompt that takes the pointer, so
the release always arrived before the stream — the click transcribed a recorder that did not exist yet
and the stream that landed afterwards stayed open with nothing holding it. StrictMode made it worse:
the press started the microphone from inside a `setState` updater, which React double-invokes, so each
press asked for two streams and leaked the loser. The control now **latches** (press to start, press
again to stop), the machine has an explicit `arming` phase for the gap, and the transitions are applied
off a ref instead of an updater (`dictation.ts`, `useDictation.ts`; corrected the same day). **Still not verified against a real microphone end to end** — that is the rest
of phase 4, and nothing here claims a transcript has ever come back.

The counterpart to `docs/voice-plan.md`. That document made the Atelier answer out loud; this one lets
the operator instruct it out loud, hands in an engine bay, because typing a plate and a five-digit
odometer reading on a laptop balanced on a wing is the gesture the whole product is supposed to remove.

The wire row is in `dashboard/CONTRACT.md`. Read it first — it is the authority,
this file is the build order. Nothing in it names a provider, which is the point: the
decision below is swappable and the rules are not.

## Decisions locked (2026-09-11)

| | |
|---|---|
| Provider | **ElevenLabs Scribe** — the same vendor and the same server-side key the voice already uses |
| What is dictated | the existing **`instruction`** string — `POST /agent/turn` is untouched. **No new wire verb**, no frame, no `UIAction` |
| Submit rule | ~~the transcript fills the composer; the turn starts on Enter~~ → **the transcript sends itself** (reversed 2026-09-11 on the operator's use of the Enter version). It still fills the composer instead whenever it cannot be sent — a turn already running, an inert composer |
| Recording mode | **push-to-talk**. No wake word, no always-listening, no VAD |
| Where the key lives | `api/` only. The browser never sees it and only ever posts to same-origin `/stt` |

### Reversed the same day: local whisper first → ElevenLabs

This plan was written (2026-09-11, morning) with **a local whisper sidecar** as the provider, on the
argument that transcription has no quality ceiling equivalent to the one that forced the voice hosted
(`docs/voice.md` § ElevenLabs free tier), and that a local model buys a demo which still listens with
the network unplugged. The measurement below **refuted the premise** and the decision was reversed the
same afternoon. The original reasoning is kept here rather than deleted, because the local sidecar is
still the right *second* backend and whoever revisits it needs to know what was already measured.

What refuted it: on this hardware the local models are a choice between **fast and wrong** or **slow
and large**. `small` answers in ~2 s but spells the operator's own car "Masda" and turned « cent
quarante-deux mille » into `140 2000`; `large-v3-turbo` gets every brand and digit right but needs
**1.6 GB on disk and ~7 s for a 4 s line** — slower than real time, on a control the operator is
holding. Paying 1.5–1.6 GB and a 7 s wait for a transcript that still wants correcting is a worse
deal than a hosted call, and « more professional » is the actual product requirement: this is the
input path for a garage's real records.

**What the reversal costs, stated plainly.** The offline-demo property is gone — a conference network
that dies takes dictation with it, exactly as it takes the voice (`voice-plan.md` § Risks, still open).
And recordings of the operator's voice now leave the machine to a vendor, where before they would not
have. Both are why the seam keeps a second backend as a first-class possibility rather than a footnote.

## Measured — the vendor's wire, against the live API (2026-09-11)

The first real press produced a **silent 503 on every recording**, and the logs said only
`Scribe refused the recording (HTTP 400)`. Probed by hand against `POST /v1/speech-to-text` with the
stack's own key (a 1 s generated tone as the file, so nothing was transcribed and nothing was billed):

| what was sent as `keyterms` | vendor answer |
|---|---|
| `["Mazda"]` — a JSON array, one field | **400** `invalid_keyword` · *"Some keyword contains invalid characters"* |
| `["Mazda 6"]`, `["contrôle technique"]`, `["5W30"]` | **400**, identically — the CONTENT is irrelevant |
| `keyterms=Mazda` — a plain value | passes validation (401 below) |
| two repeated `keyterms` fields | passes validation |
| `contrôle technique`, `bougies d’allumage`, `5W-30`, `Mazda "6"`, `C4 Picasso` as plain values | all pass |

**Each `keyterms` field is ONE keyword, read verbatim.** A JSON array is therefore a keyword spelled
`["Mazda"]`, and `[`/`"`/`]` are among the characters the vendor refuses — so the whole request dies,
for every operator who owns a car. Fixed by sending one multipart field per term
(`ElevenLabsTranscriptionClient`, `TranscriptionTest::testEachBiasTermIsItsOwnFieldRatherThanAJsonArray`).
Accents, typographic apostrophes and hyphens are all fine; `Keyterms::FORBIDDEN` already strips the
bracket family, which is why no individual term was ever the problem.

**The second blocker, still open: the API key is not entitled.** Every well-formed request answers
`401 missing_permissions` — *"The API key you used is missing the permission speech_to_text"*. That is
an account setting (the key's permissions in the ElevenLabs dashboard), not code, and **no transcript
has ever come back from this stack**. Until it is granted, dictation records, posts, and degrades to
silence exactly as designed.

**The refusal body is now carried.** Both failures above were invisible: the dashboard shows a mic that
fills nothing in, and the log said `HTTP 400` with no detail, though the vendor had named the parameter
in a body the client discarded. The client puts it in the exception and `TranscriptionController` logs
it at `notice` — one line would have replaced this whole investigation.

**What the vendor's own reference says, checked the same day** (`/docs/api-reference/speech-to-text/convert`):
`keyterms` is typed *"Array of strings"* in a multipart body — which is the TYPE, not the encoding, and
the encoding is the part that bit us: an array in multipart is one field per value, exactly as the
probes show. Also from that page, and worth keeping: **max 1 000 terms**, 50 characters and 5 words
each, unsupported characters `< > { } [ ] \` (precisely `Keyterms::FORBIDDEN`), a flat **+20 %**
whenever the parameter is present, and — the reason our own cap of 100 stays where it is — **over 100
terms a 20-second minimum billable duration applies per request**, so 100 is a billing cliff, not a
vendor ceiling. `scribe_v2` is listed as valid for this REST endpoint (`scribe_v2 Realtime` is a
different, WebSocket endpoint), so the model id is not a third blocker — though it is still unexercised,
because the permission check fires before model validation.

## Measured — the local route, and why it lost (2026-09-11)

Same protocol as the TTS tables in `docs/voice.md`: this hardware (Ryzen 7 PRO 6850U, **CPU only**, no
GPU), faster-whisper / CTranslate2 **int8**, French, `cpu_threads=8` with a container build competing
for CPU. Corpus: six real garage instructions rendered through the two locally installed piper French
voices (`fr_FR-siwis-medium`, `fr_FR-tom-medium`) = 12 files, 2.9–4.4 s each.

**This is a clean-signal upper bound on accuracy, not a microphone measurement** — no room noise, no
mic, no disfluency. A real engine bay is worse than every number here.

| model | load | avg RTF | a 4 s line | on disk |
|---|---|---|---|---|
| `small` int8 | 1.10 s | **0.55** | ~2.0 s | 464 MB |
| `medium` int8 | 3.37 s | 1.35 | ~5.3 s | 1.5 GB |
| `large-v3-turbo` int8 | 27.4 s | 1.87 | ~7.0 s | 1.6 GB |

Warm re-runs tracked cold within noise, so the per-utterance cost dominates and the model load is a
genuine one-off — the `pocket-tts` lesson (`docs/archive/pocket-tts.md`) held here too.

Accuracy, verbatim, on the sentences that matter:

| reference | `small` | `medium` | `large-v3-turbo` |
|---|---|---|---|
| « …la Mazda 6 à cent quarante-deux mille kilomètres » | Mas**d**a 6 · `140 2000` km (tom) | Mazda 6 · `140,000` km (tom) | Mazda 6 · `142 000` km |
| « …le moteur de la Clio » | « l'acquillot » (tom) | « l'appui haut » (tom) | Clio |
| « plaquettes de frein avant » | « paquette » | « paquets de freins à vent » | « paquette » |
| « cinq W trente » | `5W 30` / `5W30` | `5W-30` | `5W30` |
| « contrôle technique » | correct | correct | correct |

Two things to carry forward. **Every model digitises spoken numbers** (« cent quarante-deux mille » →
`142 000`), which is convenient for the agent and means no denormalisation layer is needed on this
side. And **no size fixes « plaquettes »** — the plural is lost by all three, so that one is not a
size-solvable problem and would need vocabulary biasing.

**Not measured, and deliberately abandoned:** whether a domain `initial_prompt` (priming the model
with the owner's own vehicle makes) repairs the proper-noun errors on `small`. It very likely would,
and it is the reason `small`-with-priming was worth a second experiment — but the run was cut when the
provider decision changed, so **nothing is claimed about it**. If the local sidecar is ever revisited,
that is the first experiment to run, and note that `onerahmet/openai-whisper-asr-webservice` documents
**no prompt parameter**, while `speaches` (OpenAI-compatible) does.

## Phase 1 — the `api/` seam and endpoint ✅ BUILT 2026-09-11

Deliberately the **same shape** as `App\Speech`, which is itself the shape of `App\Agent\WebSearch`.
Do not invent a third form.

| | |
|---|---|
| `api/src/Transcription/TranscriptionClient.php` | the interface: `transcribe($audio, $contentType, Locale): string` ✅ |
| `api/src/Transcription/TranscriptionBackend.php` | the enum, `elevenlabs` ✅ |
| `api/src/Transcription/SelectableTranscriptionClient.php` | the `!service_locator` router ✅ |
| `api/src/Transcription/TranscriptionUnavailable.php` | the domain exception ✅ |
| `api/src/Transcription/ElevenLabsTranscriptionClient.php` | the vendor call ✅ |
| `api/src/Controller/TranscriptionController.php` | `POST /stt` ✅ |
| `api/config/packages/security.yaml` | firewall `^/(garage\|agent\|tts\|stt)` + an `access_control` line ✅ |
| `api/tests/Functional/TranscriptionTest.php` | 12 cases, green ✅ |
| `api/tests/Mock/RecordingTranscriptionHttpClient.php` | the vendor transport double ✅ |

No cache layer, and that is a decision rather than an omission: `CachingSpeechClient` pays off because
the same sentence is spoken again and again, whereas no two recordings are ever byte-identical. A
cache here would be a disk full of single-use entries.

Test-first, `AppTestCase` + a recording `MockHttpClient`, **no test ever calling the real vendor** —
mirroring `tests/Functional/SpeechTest.php`. The cases:

- anonymous `POST /stt` → **401**, and no vendor call
- authed → **200**, `{"text": …}`, the transcript verbatim
- the recording reaches the vendor **byte for byte** (a re-encode would degrade accuracy invisibly)
- `Accept-Language` → the vendor's language field; `de-DE` falls back to French
- silence → **200** `{"text":""}`, never an error
- empty body, over-cap body, or a non-`audio/*` content type → **400**, and no vendor call
- a vendor 5xx or transport failure → **503**, empty body, never a 500

**The cap.** `/tts` caps at 400 characters because a toast is one line; `/stt` caps at **1 MB** for the
same reason in the other unit — 30 s of opus is ~90 KB, so a body past that is a thumb on the control.
Check PHP's `post_max_size` in the container before trusting it: an upload truncated by php.ini
presents as a transcript that stops mid-sentence.

**Ownership.** Authentication, not `OwnerContext` — the reasoning `/tts` already recorded: nothing
owner-scoped is read, and what is protected is the key and the bill, which authentication protects.
**This is the line to revisit if vocabulary biasing is ever added** (below): priming the model with the
owner's own vehicle makes and models WOULD make `/stt` an owner-scoped read, and then `OwnerContext`
belongs here.

### The vendor contract, as verified 2026-09-11

`POST https://api.elevenlabs.io/v1/speech-to-text`, `xi-api-key` header, `multipart/form-data`, audio
in the field **`file`**. What this client sends and why:

| field | value | why |
|---|---|---|
| `model_id` | `scribe_v2` (env `ELEVENLABS_STT_MODEL`) | **`scribe_v1` is deprecated** at the vendor |
| `language_code` | the `Locale` value — `fr` / `en` | ISO 639-1, which is already exactly what `App\I18n\Locale` holds; nothing translates. Sent always rather than trusting auto-detect: one dictated sentence is thin evidence |
| `tag_audio_events` | **`false`** | it defaults to TRUE and decorates the transcript with `(laughter)` / `(applause)`. That text would land in the composer as words the operator has to delete |
| `no_verbatim` | **unset** | it strips filler words and false starts. The binding rule is *the operator's words verbatim*; cleaning them up is a rephrase by another name |

Response: the transcript is at `text`; a recording with no speech answers **200 with an empty `text`**,
which is why silence is a 200 all the way to the composer. Failures are 401 (bad key), 400/413 (body
refused), 429 (`too_many_concurrent_requests` — the plan's tier allows 2–15 concurrent). All of them
become one silent 503.

### `keyterms` — the garage as bias vocabulary ✅ BUILT 2026-09-11

`keyterms` is a JSON array of up to 100 terms (≤50 chars, ≤5 words, no `< > { } [ ] \`), at a
**+20 % credit surcharge whenever it is present**. Every transcriber measured fails on the same class
of word — proper nouns and identifiers, « Masda » for Mazda, « l'acquillot » for Clio, and
« plaquettes » that no model size fixed — and those are exactly the words this app already holds as
rows in the operator's garage.

| | |
|---|---|
| `api/src/Transcription/Keyterms.php` | **pure**: the vendor's hard limits (cap 100, 50 chars, 5 words, forbidden characters stripped, case-insensitive dedupe, inner whitespace collapsed). They are hard limits — one bad term is a 400 for the WHOLE request — so this is what stops one oddly named car from costing its owner the ability to dictate. 8 unit tests |
| `api/src/Transcription/GarageVocabulary.php` | the owner-scoped read: the 30 most-recently-touched vehicles' make, model and plate, then the viscosity grades and the locale's maintenance terms |
| `TranscriptionClient::transcribe()` | grew a fourth `list<string> $keyterms` parameter; the vendor client normalizes again, as the vendor-safety boundary for any caller |

Three decisions worth keeping:

- **Owner terms come FIRST in the list, generic terms after.** The cap keeps the head, so a large
  garage loses the filler and never the names of its own vehicles.
- **An empty garage sends no `keyterms` field at all** — not an empty array. The surcharge is charged
  on presence, and a parc with nothing in it has no vocabulary worth a fifth of every bill.
- **Only 30 vehicles, most-recently-touched.** The cars being worked on are the ones about to be
  dictated, and an unbounded read on every dictation is a cost with no ceiling.

**This moved the ownership line.** `/stt` is now an owner-SCOPED read, which it was not when phase 1
was written: the authentication gate is also what keeps one tenant's vehicle names out of another's
request — which would leak their garage to the vendor *and* bias this operator's words toward cars
they do not own. `TranscriptionTest` pins both halves of that (the owner's garage IS sent, another
owner's is never). The `VehicleRepository` scoping does the work; no `OwnerContext` call was needed in
the controller after all, because every query in that repository is already scoped to the current
owner.

**Cost, stated plainly:** every dictation from a non-empty garage now costs 1.2× the base rate.

## Phase 2 — the local sidecar, as the second backend (deferred)

Not cancelled: the seam's entire purpose is that the provider is one class, one enum case and one
locator line, and the § Risks entry it retires (a dead network kills dictation) is real. Deferred
because the measurement above says a local model on this CPU is either approximate or slow, and
neither is what a garage's records deserve. Revisit with the priming experiment first.

## Phase 3 — the `dashboard/` half

The `pcm.ts` / `speaker.ts` split, again, for the same reason: every rule pure and tested, the browser
wiring left honest about being untestable.

| | |
|---|---|
| `dashboard/src/noyau/dictation.ts` | **pure, BUILT 2026-09-11** — the `idle → recording → transcribing` machine, the 30 s self-stop cap, the denied-permission latch, and `merge()` (the transcript appends to typed text, never replaces; an empty transcript leaves the input untouched). 15 tests |
| `dashboard/src/noyau/recorder.ts` | **impure**, BUILT — `getUserMedia` + `MediaRecorder`, container picked from what the browser supports (webm/opus, ogg/opus, mp4 for Safari). No jsdom coverage, and its docblock says so |
| `dashboard/src/noyau/useDictation.ts` | BUILT — permission, the 30 s deadline, `POST /stt` (plain `fetch` + bearer, never `authFetch`: a 401 must not seize the session), every failure collapsing to "the composer is unchanged" |
| `dashboard/src/scene/Composer.tsx` | BUILT — the mic control **inside** the one composer, held not latched: pointerdown/up, `pointerleave`/`blur` also close it, and Space/Enter keydown→keyup is the keyboard equivalent of the hold. Gone from the DOM once permission is refused |
| `dashboard/src/i18n/{fr,en}.ts` | BUILT — `scene.dictate` / `scene.dictating` |
| `dashboard/src/Atelier.tsx` + `scene/SceneView.tsx` | BUILT — an open mic HOLDS the audition by passing `audition && !listening` into `useVoice`, which reuses the proven mute path (current line stops, queue empties), and lights Le Noyau's existing `listening` phase |

The mic needs a **secure context and a user gesture** — `https://localhost` qualifies even with the
self-signed cert, and the press is the gesture, the same one that unlocks the `AudioContext`. Firefox deserves an explicit check: it refused the *output*
path on this origin for autoplay reasons (`FUTURE.md` § 14), and mic permission is a separate grant.

## Phase 4 — live, and what must be measured before it ships

- French accuracy on **real microphone** audio in a noisy room, not the TTS corpus above.
- Release-to-transcript latency end to end through Caddy — the number the operator actually feels.
- The audition interplay: open the mic while a line is being spoken, confirm it cuts mid-word.
- A denied permission, a 503 vendor, and a silent recording — each must cost one gesture and nothing else.
- The **bill**: Scribe is billed per minute of audio (×1.2 with `keyterms`), which the voice's
  `TTS_MONTHLY_CHAR_BUDGET` character counter cannot express. Whether a per-owner guard is needed here
  is a phase-4 question, not a phase-1 one, but it is a question.
- **Whether the bias actually works.** The vehicle names are sent; that they repair « Masda » → Mazda
  is *inference from the local measurement*, never measured against Scribe. Say « enregistre une
  vidange sur la Mazda 6 » into a real mic and read the transcript — and check the inverse risk too,
  bias bleed: a term appearing in a transcript where it was not spoken.

## Risks

- **A dead network kills dictation**, same as the voice. Retired only by phase 2.
- **Accuracy on domain vocabulary** (part references, viscosity grades, plates) is the real unknown on
  any provider; the composer-review rule is what makes a wrong word cheap, and it is why that rule is
  binding rather than a preference.
- **A microphone in a demo room hears the room.** Push-to-talk is the mitigation and it is binding.
- **Recordings leave the machine.** A consequence of the provider choice, noted so nobody rediscovers
  it in a privacy review.
