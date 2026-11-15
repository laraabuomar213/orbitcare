# AI architecture

The AI Assistant works end to end **today**, without an AI provider: consultations, health
data, symptom detection, specialist routing, handoffs, history and safety rules all run on the
backend. What a provider adds is the *interpretation* text. Until one is configured, every reply
says so ("AI service is currently unavailable — no AI provider is configured") and nothing is
generated in its place.

## Principles

1. AIs receive **only** data OrbitCare has: replayed measurements (with raw IDs and timestamps),
   trends, alerts, the astronaut's own profile, and what the astronaut wrote.
2. **No diagnosis.** Wording like "this may indicate…", never "you have…".
3. Every reply keeps **OBSERVED DATA** (stored values) / **AI INTERPRETATION** (provider only) /
   **RECOMMENDED NEXT STEP** (backend rules) separate.
4. **Urgency and specialist routing are backend rules** (`SpecialistRouter`), never frontend code
   and never decided by the AI alone. Alerts come from the deterministic alert engine.
5. Every AI request is stored in `ai_analysis` with the IDs of the measurements analysed.
6. Keys live only on the server (environment / secret manager).

## Consultation flow

```
POST /api/ai/conversations/{id}/messages
  → session user (never an ID from the browser) + ownership check (ConversationService)
  → HealthContextService   shared health-data layer: profile, current values, trends (last 10 min vs earlier),
                           unresolved alerts, cross-metric patterns — aggregated with SQL, not dumped
  → SymptomDetector        phrases the astronaut wrote (negations ignored) — config/ai-specialists.php
  → SpecialistRouter       deterministic scores → recommended specialist + confidence + reasons; urgency level
  → PromptBuilder          SYSTEM INSTRUCTIONS · PATIENT PROFILE · HEALTH DATA · HISTORICAL TRENDS ·
                           CURRENT CONVERSATION · PREVIOUS RELEVANT CONVERSATIONS · TASK
  → AIProvider->run('consultation', prompt)   NotConfiguredProvider by default
  → validation (required fields), timeout/error → retryable error state
  → structured payload saved with the message → frontend renders it
```

## Specialists (only where data exists — `config/ai-specialists.php`)

| Specialist | Uses | Notes |
|---|---|---|
| General Doctor AI | all four metrics, all alerts | Entry point; recommends a specialist |
| Respiratory AI | SpO₂, respiratory rate | |
| Cardiologist AI | heart rate, pulse rate | |
| Neurology AI | reported symptoms; heart rate & SpO₂ as context | **No neurological measurements exist** — said explicitly in every reply |

A specialist consultation opened from the General Doctor gets a **handoff** (patient concern in
the astronaut's own words, detected symptoms, relevant measurements and trends, the General
Doctor's reasons, reason for referral). Opening a specialist directly still uses the latest
General Doctor consultation as context. Reopening an active specialist after new General Doctor
messages appends a handoff update.

## Routing rules (summary)

Per specialist: +2 per matching reported symptom; +3/+2 for a relevant metric that is RED/YELLOW
now or has an unresolved RED/YELLOW alert; +1 if out of range in ≥ 20 % of recent readings; +1
when symptom and measurement point the same way. Highest score ≥ 2 is recommended
(≥ 5 high, ≥ 3 moderate confidence). **Urgent** next step when a red-flag symptom is reported
(chest pain, fainting, unable to breathe, confusion, difficulty speaking) or any value/alert is critical.

## Provider contract

`AIProvider::run(string $task, array $input): AIResult`. For `consultation`, `$input` has
`system`, `prompt` and `output_fields`; the provider must return JSON with
`reply, interpretation, recommended_next_step, follow_up_questions, insufficient_data`.
Missing fields, exceptions and timeouts become `status: error` (shown with a Retry button).

## Online AI: Gemini (connected 2026-11-12)

- `AI_PROVIDER=gemini`, `AI_API_KEY` (from https://aistudio.google.com/apikey), `AI_MODEL`
  (default `gemini-flash-lite-latest`: the largest free daily quota). Check with `php cli/check-ai.php`.
- **Space link:** the AI is used whenever there is a link to the ground. With no link, a timeout, the
  free quota used up, or a malformed/blocked answer, `GeminiProvider` returns an error and the
  astronaut gets OrbitCare's on-board rule-based reply with a short note (`ai.error.*`) — never nothing.
- **Exact reply shape:** an agent may pass `output_schema` (Gemini `responseSchema`); the crew emergency
  report uses it so the steps always come back as a list in both languages (without it Gemini sometimes
  returned "1. … 2. …" as one string or dropped a field).
- **Privacy:** on the free tier Google may use prompts to improve its products. The prompt never
  contains names or astronaut IDs (`PromptBuilder`, `AIService::explainAlert`); the demo uses public
  recorded data. Switch to a paid key before real crew data. Logs record status and timing only.

## Official medical sources (MedlinePlus + openFDA)

`MedicalSourcesService` + `config/medical-sources.php`, free and from US government sources:
- **MedlinePlus (NLM):** a trusted page for each symptom detected in the message ("Trusted sources").
- **openFDA:** the official label of each medication in the astronaut's profile; if a reported symptom
  is listed in its warnings/side effects, the reply says so with the DailyMed link, and tells the
  astronaut not to change a medication on their own.
- Both are added to the AI prompt (`official_sources`) and shown even without the AI.
- Cached 24 h (`medical_source_cache`, as NLM asks); during a loss of signal the first failed request
  stops further calls, and cached answers are still shown.

## Connecting Claude (when ready)

1. `composer require anthropic-ai/sdk` (official Anthropic PHP SDK — preferred over hand-written HTTP).
2. Add `backend/services/ai/providers/AnthropicProvider.php` implementing `AIProvider`: call the
   Messages API with model `claude-opus-5-5`, `system` = `$input['system']`, one user message =
   `$input['prompt']`, structured JSON output for the five fields; map API/timeout errors to `AIResult::error()`.
3. Register it in `AIService::providerFromConfig()` and set `AI_PROVIDER=anthropic` and `AI_API_KEY` on the server.

Nothing in the frontend or the API changes. Test with `tests/run.php` (fake providers already
cover success, timeout and malformed answers).

## Other agents

`CardiovascularAgent`, `RespiratoryAgent`, `GeneralHealthAgent`, `SymptomInterviewAgent` and
`CoordinatorAgent` (used by `/api/ai/analyze` and alert explanations) keep their documented
input/output contracts and reject incomplete provider answers.

## Medical API

`MedicalApiService` handles auth header, JSON formatting, required-field validation, timeouts
(15 s), HTTP/network errors, logging and auditing. Configure with `MEDICAL_API_KEY` and
`MEDICAL_API_BASE_URL`. Without them, it refuses to run.
