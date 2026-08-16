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
