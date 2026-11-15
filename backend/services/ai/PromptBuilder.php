<?php
// Builds the structured prompt sent to an AI provider for one consultation turn.
// Sections are always in this order and clearly separated:
//   SYSTEM INSTRUCTIONS · PATIENT PROFILE · HEALTH DATA · HISTORICAL TRENDS ·
//   CURRENT CONVERSATION · PREVIOUS RELEVANT CONVERSATIONS · TASK
// Only data selected by HealthContextService is included — never raw database dumps.
declare(strict_types=1);

final class PromptBuilder
{
    // Fields the provider must return (validated by ConsultationService).
    public const OUTPUT_FIELDS = ['reply', 'interpretation', 'recommended_next_step', 'follow_up_questions', 'insufficient_data'];

    public function build(array $specialist, array $context, array $conversation, array $previous, ?array $handoff, array $routing): array
    {
        $language = t('ai.prompt.language');   // the astronaut's interface language

        // Each specialist's own clinical identity (config/ai-specialists.php): role, red flags, space knowledge.
        $identity = '';
        if (!empty($specialist['role'])) {
            $identity .= "\n\nYour clinical focus and how you think:\n{$specialist['role']}";
        }
        if (!empty($specialist['red_flags'])) {
            $flags = implode("\n- ", $specialist['red_flags']);
            $identity .= "\n\nRed-flag signs — if the astronaut's message or data shows any of these, say clearly and without alarm that they should contact the mission medical officer now, and still answer their question:\n- {$flags}";
        }
        if (!empty($specialist['space_notes'])) {
            $identity .= "\n\nSpaceflight knowledge to reason with (do not state these as facts about the astronaut unless their own data shows it):\n{$specialist['space_notes']}";
        }

        $system = <<<TXT
You are the {$specialist['name']} inside OrbitCare, a health-monitoring and decision-support system for astronauts.
Focus: {$specialist['description']}{$identity}

How to talk with the astronaut (you are their health companion, like a caring doctor on a long mission):
- ANSWER THE LATEST MESSAGE FIRST and directly. If they ask "should I sleep?", start with a clear answer, then why.
- REMEMBER THE CONVERSATION (CURRENT CONVERSATION and PREVIOUS RELEVANT CONVERSATIONS): refer to what they told
  you before ("earlier you mentioned the headache…"), notice changes, and build on your earlier advice.
- DO NOT REPEAT YOURSELF: mention recorded values only when they are new, changed, or directly answer the question.
  Never restate the same data summary you already gave in this conversation.
- GUIDE AND COACH: give concrete, safe steps they can do now and habits that help them improve (rest and sleep
  routine, hydration, calm breathing, the daily exercise countermeasure, light/schedule, staying connected with crew
  and family, using the daily check-in and alertness check to follow progress). Tie each step to their own data
  (check-ins, alertness test, measurements) when you can. For worries about mood, stress or loneliness: be warm and
  supportive, suggest evidence-based coping, and offer the behavioral health support / medical officer.
- Be warm, natural and specific; vary your wording; 3–7 short sentences in "reply".

Safety rules:
- Use ONLY the information in this prompt. Never invent measurements, symptoms, history, test results or dates.
- You do not diagnose. Never say "you have <disease>". Use wording such as "this may indicate", "a concerning pattern was detected", "your recorded values are outside the monitoring range".
- If the data is insufficient, say so honestly.
- The health data is a replay of recorded data from a public dataset, not a live wearable; do not overstate its certainty.
- If OrbitCare's routing marks the next step as urgent, or symptoms are severe or worsening, clearly tell them to contact the mission medical officer now — but still answer their question.
- Never tell them to start, stop or change a medication; refer medication questions to the medical officer.
- Write in the language of the astronaut's LATEST message (Arabic or English); if unclear, use {$language}. Every text field in that language.

Return JSON with these fields:
- reply (string): your answer to the latest message, as described above;
- action_plan (array of 2–4 short, concrete steps to do now or to improve over the next days);
- interpretation (string): one or two sentences on what the data may indicate;
- recommended_next_step (string);
- follow_up_questions (array of 0–2 strings, only questions that really help);
- insufficient_data (boolean).
TXT;

        $sections = [
            // No name or astronaut ID ever leaves OrbitCare: the AI needs the health facts, not the person.
            'PATIENT PROFILE' => $this->json(array_diff_key($context['profile'], ['astronaut_code' => true, 'name' => true])),
            'HEALTH DATA' => $this->json([
                'data_status' => $context['data_status'],
                'last_updated' => $context['data_last_updated'],
                'source' => $context['replay'] ? "Recorded data replay ({$context['replay']['source']}, record {$context['replay']['record_id']})" : null,
                'current_measurements' => $context['current'],
                'unresolved_alerts' => $context['alerts'],
                'overall_status' => $context['overall_status'],
                'self_reported_daily_checkins' => $context['self_reports'] ?? null,
            ]),
            'HISTORICAL TRENDS' => $this->json([
                'window_seconds' => $context['window_seconds'],
                'per_metric' => $context['trends'],
                'detected_patterns' => $context['patterns'],
            ]),
            'CURRENT CONVERSATION' => $this->json([
                'specialist' => $specialist['name'],
                'handoff_from_general_doctor' => $handoff,
                'messages' => $conversation,
            ]),
            'PREVIOUS RELEVANT CONVERSATIONS' => $this->json($previous ?: 'None'),
            'TASK' => "As the {$specialist['name']}, answer the astronaut's LATEST MESSAGE: \"" . $this->latestUserMessage($conversation) . '". '
                . 'Use the conversation as memory and do not repeat what you already said. '
                . 'OrbitCare\'s rule-based routing for this turn (backend, deterministic — keep its urgency): ' . $this->json($routing)
                . ' Then give a short action plan to help them feel better and improve.',
        ];

        $prompt = '';
        foreach ($sections as $title => $content) {
            $prompt .= "### {$title}\n{$content}\n\n";
        }

        return ['system' => "### SYSTEM INSTRUCTIONS\n" . $system, 'prompt' => trim($prompt), 'output_fields' => self::OUTPUT_FIELDS];
    }

    private function latestUserMessage(array $conversation): string
    {
        foreach (array_reverse($conversation) as $message) {
            if ($message['sender'] === 'user') {
                return str_replace('"', '\'', $message['text']);
            }
        }
        return '';
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
