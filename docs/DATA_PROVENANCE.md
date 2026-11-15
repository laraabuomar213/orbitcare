# Data provenance

## Source

**BIDMC PPG and Respiration Dataset, version 1.0.0** — PhysioNet
https://physionet.org/content/bidmc/1.0.0/

- 53 recordings, 8 minutes each, from adult patients in intensive care at the Beth Israel
  Deaconess Medical Center (extracted from MIMIC II).
- **These are hospital ICU patients, not astronauts.** OrbitCare assigns a recording to a
  demo account only to demonstrate the monitoring pipeline. Patient age/sex from the source
  are stored in `source_records` for provenance but are never shown as the astronaut's own data.
- Please cite the dataset and PhysioNet as required on the dataset page, and check its
  license terms there before redistributing the files.

Files used per record (`bidmc_csv/`): `bidmc_NN_Numerics.csv` (1 Hz numerics),
`bidmc_NN_Fix.txt` (record metadata) and, for the records in `replay.record_pool`,
`bidmc_NN_Signals.csv` (125 Hz waveforms, see "Waveforms on the vest" below).

## Waveforms on the vest and headband

`php cli/build-waveforms.php` downloads `bidmc_NN_Signals.csv` unchanged into `data/raw/` and packs three
columns into `data/processed/waveforms/bidmc_NN.bin` (int16 = published value × 10000; a missing value stays
missing, never filled). `GET /api/health/waveform?seconds=2..12` returns the window that ends at the same
replay position as the dashboard numbers. The 3D vest page (`prototypes/vest-3d`, linked from the menu and
from each vital card) shows each signal on the sensor that would measure it:

| Source column | Shown on | With the numbers |
|---|---|---|
| `II` (ECG lead II) | Vest chest electrodes | `heart_rate` |
| `PLETH` (PPG) | Headband forehead sensor | `spo2`, `pulse_rate` |
| `RESP` (respiration) | Vest breathing band | `respiratory_rate` |

Honest limits, stated on the page: the source measured PPG at the **fingertip** and respiration by
**impedance through chest electrodes**; it has **no EEG**, so the sleep simulation's brain waves stay
simulated (labelled SIMULATED DATA).

## Open sensor data (the other vest / headband sensors)

`php cli/ingest-open-sensors.php` downloads the files unchanged into `data/raw/radlab/` and `data/raw/noneeg/`
and stores them in `sensor_sources` (one row per recording, with dataset, URL, licence) and `sensor_samples`
(immutable: triggers reject UPDATE/DELETE). `GET /api/health/sensors` returns each sensor at the same replay
position as the dashboard; the 3D vest shows it on the sensor that would measure it.

| Vest / headband sensor | Source | What is stored |
|---|---|---|
| Personal dosimeter | **NASA OSDR RadLab** — ISS, DosTel1 detector, Columbus module, 2022-04-01 (UTC), https://visualization.osdr.nasa.gov/radlab/ | Absorbed dose rate as published (µGy/h), real timestamps. Shown with the whole-day mean and mean × 24 h (**derived**). Same for every crew member (same station). |
| Skin temperature | **PhysioNet** — A Non-EEG Dataset for Assessment of Neurological Status v1.0.0 (ODC-By 1.0), https://physionet.org/content/noneeg/1.0.0/ | 1-s mean of the 8 Hz wrist temperature (°C) |
| Skin conductance (GSR) | same | 1-s mean of the 8 Hz EDA signal, in the published units ("NU") |
| Motion sensor | same | 1-s standard deviation of the acceleration magnitude (**derived** movement index, published units) |
| Muscle activity (EMG) | The astronaut's own daily check-in | Resistance and aerobic minutes, as entered |
| Dosimeter (logged) | The astronaut's own daily check-in | Dose they entered (mSv), shown next to the RadLab rate |

- **Different people:** the noneeg volunteers (20 healthy adults, wrist sensors) are not the BIDMC patients.
  `bidmc_NN` always uses volunteer `((NN − 1) mod 20) + 1`, and every reading says which volunteer it comes from.
- A second is stored only if all 8 samples in it are valid; nothing is filled in. RadLab rows with a missing or
  impossible dose rate (< 0 or > 1000 µGy/h) are rejected; a repeated timestamp is stored once.
- **Not covered by open data:** blood pressure (no open dataset in a matching format was found) and sleep EEG
  (the sleep simulation stays labelled SIMULATED DATA).
- Cite: Birjandtalab et al., IEEE SiPS 2016 (noneeg) and the standard PhysioNet reference; NASA OSDR RadLab.

## Metrics shown in OrbitCare

| OrbitCare metric | Source column | Unit (as published) | Notes |
|---|---|---|---|
| `heart_rate` | `HR` | bpm | Bedside monitor heart rate |
| `pulse_rate` | `PULSE` | bpm | Pulse rate from the pulse oximeter (PPG) |
| `respiratory_rate` | `RESP` | breaths/min | |
| `spo2` | `SpO2` | % | Peripheral oxygen saturation |

**Not shown, because the source has no such data:** body temperature, blood pressure,
anything else. The dashboard shows four real measurements instead of the reference
design's temperature card.

## What every stored value carries (`raw_measurements`)

`id`, `source` (dataset name + version), `source_record_id` (e.g. `bidmc_03`), `source_file`,
`source_row` (line in the CSV), `original_timestamp` (the CSV's `Time [s]`: seconds from the
start of the recording), `metric`, `value` (unchanged), `unit` (unchanged),
`ingestion_timestamp`, `processing_status`.

The source's absolute dates are de-identified, so only the relative time is used.

## Ingestion rules

| Case | What happens |
|---|---|
| Valid number in range | Stored exactly as recorded |
| `NaN` / empty | **Not stored**, counted as missing. Never filled, interpolated or carried forward. |
| Not a number, or outside physical limits (e.g. SpO2 > 100 %, HR > 300 bpm) | Rejected, counted, listed in the ingestion report |
| Unknown header / wrong file | Whole file refused |

Per-record counts are in `source_records` and `data/processed/<record>_ingestion.json`.
Known gaps in the source: e.g. `bidmc_13` (146 missing RESP), `bidmc_19` (102 missing PULSE and 102 missing SpO2).

`raw_measurements` is immutable: database triggers reject any UPDATE or DELETE.

## Derived data (clearly separated)

| Data | Table | Links to |
|---|---|---|
| Classification of each replayed value (GREEN/YELLOW/RED) + threshold version + replay time | `processed_measurements` | `raw_measurement_id` |
| Alerts | `alerts` | `source_measurement_id`, `first_measurement_id`, rule text, threshold version |
| Summary statistics (min/max/mean, % in range) | computed on request | labelled **Derived** in the UI |
| AI output | `ai_analysis` | `input_measurement_ids`, `input_alert_id` |

## Replay time vs. recorded time

The UI shows two times: *replay time* (when the value appeared in OrbitCare) and *recording
time* (`rec mm:ss` = `original_timestamp`). Hovering a vital sign shows its raw measurement ID,
record, row and offset; the Analytics table lists them for every value.

A metric is shown as **"Data unavailable"** when its newest sample is more than 10 s older
(in recording time) than the replay position — e.g. during a gap of `NaN` values.

## Demo assignments (facts checked against the loaded data)

| Account | Record | What the real data contains |
|---|---|---|
| Astronaut 01 | `bidmc_03` | Every value inside the monitoring ranges → no alerts |
| Astronaut 02 | `bidmc_21` | HR > 100 bpm from t = 0, > 120 bpm from t = 35 s → YELLOW alert, escalated to RED |
| Astronaut 03 | `bidmc_32` | SpO2 83–87 % and RESP 23–26 /min throughout → RED alerts |

New sign-ups get records round-robin from `config.php → replay.record_pool`.

## Thresholds

`config/health-thresholds.php` (version string stored with every classification and alert):

| Metric | Green | Yellow (attention) | Red |
|---|---|---|---|
| Heart rate / pulse | 50–100 bpm | 40–49 or 101–120 | < 40 or > 120 |
| SpO2 | 95–100 % | 90–94 % | < 90 % |
| Respiratory rate | 12–20 /min | 8–11 or 21–24 | < 8 or > 24 |

Sustain time: 10 consecutive samples (seconds). These are general adult resting reference
ranges for a prototype, **not clinically validated for spaceflight**.

## Self-reported indicators (Daily Check-in)

Sensors can't measure everything the challenge names (behavioral health, bone/muscle
countermeasures, immune-related symptoms, radiation readings), so the astronaut reports them in
a one-minute daily check-in. These values are the astronaut's own entries — stored as entered,
never filled in for days without a check-in, and private unless the astronaut shares them.

| Question | Scale / unit | Why (source) | Verification |
|---|---|---|---|
| Hours slept, sleep quality | h; 1–5 | Chronic sleep loss is a spaceflight stressor — [NASA](https://www.nasa.gov/reference/risk-from-inadequate-sleep-and-irregular-schedules/) | Verified (NASA page). The “under 6 h” rule is an **OrbitCare rule**, not a NASA figure |
| Fatigue | Samn-Perelli 1–7 | Validated aviation fatigue scale; labels from [Rose et al. 2023 (open access)](https://europepmc.org/article/PMC/PMC10623805), original Samn & Perelli 1982 | Labels verified (secondary source). ≥ 4 “moderate” and ≥ 6 “severe” are thresholds used in research, not regulations |
| Resistance + aerobic minutes | min; target **90 min/day** | ISS crews exercise 1.5 h/day, 6–7 days a week, to protect against muscle and bone loss — [NASA](https://www.nasa.gov/reference/risk-of-reduced-cardiorespiratory-and-musculoskeletal-fitness/); bones lose 1–1.5 %/month — [NASA](https://www.nasa.gov/hrp/hazard-gravity-fields/) | Verified (NASA pages) |
| Symptoms | chips | Crew have reported cold sores, rashes, atypical allergies and infections — [NASA](https://www.nasa.gov/reference/risk-of-altered-immune-system-responses/); eye changes — [NASA SANS](https://www.nasa.gov/reference/risk-of-spaceflight-associated-neuro-ocular-syndrome-sans/); dizziness — [NASA](https://www.nasa.gov/hrp/hazard-gravity-fields/) | Verified (NASA pages) |
| Dosimeter reading | mSv, optional | Radiation exposure is measured aboard the ISS — [NASA](https://www.nasa.gov/hrp/hazard-space-radiation/) | Verified. **No dose limit is shown**: a career limit could not be confirmed from a NASA page, so OrbitCare only totals the readings entered |
| Weekly wellbeing | PHQ-4, 0–3 per item | Validated 4-item screen (PHQ-2 + GAD-2). Items, options, bands (0–2 normal, 3–5 mild, 6–8 moderate, 9–12 severe) and subscale cut-off ≥ 3 as reported in [Dimitrov et al. 2026 (open access)](https://europepmc.org/article/PMC/PMC13525647); originals Kroenke 2009 / Löwe 2010. PHQ screeners are free to use ([phqscreeners.com](https://www.phqscreeners.com/terms)) | Verified from a secondary open-access source. The exact question stem was not found verbatim — check against the original before publication |

Not used because they could not be verified: a NASA radiation career limit, an “average ISS
sleep” figure, and the ISS “Reaction Self Test” details.

## Alertness Check (PVT-B + camera eye signs)

Measured by the astronaut's own test, not taken from a dataset. Rules in `config/alertness.php`.

| What | Rule | Source (verified 2026-11-12) |
|---|---|---|
| Test | 3 minutes; a millisecond counter appears every 1–4 s (including 1 s of feedback) | Basner, Mollicone & Dinges 2011, *Acta Astronautica* 69:949–959 — [PMC3197786](https://pmc.ncbi.nlm.nih.gov/articles/PMC3197786/), §2.2 |
| Lapse | response ≥ 355 ms (PVT-B; the 10-min PVT uses ≥ 500 ms) | same paper, §2.3 |
| False start | response with no number shown, or RT < 100 ms | same paper, §2.3 |
| Use in space | NASA's on-board "Reaction Self Test" is the 3-min PVT-B (ISS, since 2009) | [NASA Spinoff 2013](https://spinoff.nasa.gov/Spinoff2013/hm_1.html) |
| PERCLOS | share of time the eyes are ≥ 80 % closed; reflects slow closures, not blinks | Dinges & Grace 1998, FHWA tech brief — [ROSA P dot/113](https://rosap.ntl.bts.gov/view/dot/113) |

- The browser sends the raw reaction times; the **server** recomputes lapses, false starts, median and
  fastest 10 % (`AlertnessService::score`).
- Camera values are **estimates** computed on the device (MediaPipe Face Landmarker, self-hosted in
  `assets/vendor/mediapipe`). Eye closure is relative to the astronaut's own open-eye level in the same
  session. No image or video is stored or sent — only face time, blink count and eyes-closed time.
  With less than 60 s of visible face, no camera values are saved.
- **No population "normal range" is shown** for blinks or PERCLOS: no verified cut-off was found, and blink
  rate drops naturally while looking at a screen. Results are compared with the astronaut's **own**
  earlier tests instead. The "slower than usual" thresholds (10 % / +2 lapses, 25 % / +5 lapses over the
  last 14 days, at least 3 tests) are **OrbitCare rules**, not clinical or NASA standards.

## Vest device stream (Wokwi simulation)

- **What it is:** an ESP32 simulation of the planned OrbitCare vest. Heart rate, SpO2, blood pressure and
  respiratory rate come from knobs (potentiometers) turned by hand; CO2 and radiation are generated by
  the firmware. **None of it is a measurement of a person.** Every reading carries
  `data_source = "wokwi_simulated"`, its stream is named "OrbitCare vest simulation (Wokwi) — simulated
  values", and the interface shows "Simulated device — simulated values, not real measurements".
- **Analysed:** heart rate, SpO2, respiratory rate — the same validation limits as BIDMC and the same
  thresholds. **Stored but never analysed:** blood pressure, skin temperature, motion, CO2, radiation.
- **Time:** a reading's place in the stream is when it reached OrbitCare; the device's own clock is kept
  separately. Readings older than 30 s or sent in a burst from the device's queue are stored, not analysed.
- **Immutable like recorded data:** device vitals become `raw_measurements` rows (no update/delete).

## Test data

`tests/fixtures/` contains small synthetic files (record IDs 90–92, which don't exist in
BIDMC). They are only loaded into the separate `orbitcare_test` database, and any alert
they produce is stored with `is_test_fixture = 1`.
