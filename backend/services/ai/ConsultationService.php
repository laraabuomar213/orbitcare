<?php
// AI Assistant orchestration: General Doctor AI + specialists.
//
//   message → ownership check → shared health context (HealthContextService)
//           → symptoms (SymptomDetector) → routing & urgency (SpecialistRouter, deterministic)
//           → prompt (PromptBuilder) → AI provider (replaceable; may be "not configured")
//           → structured result → stored message → response
//
// Every reply keeps three things apart: OBSERVED DATA (real stored values), AI INTERPRETATION
// (only from a configured provider — otherwise clearly marked unavailable), and the
// RECOMMENDED NEXT STEP (backend rules). Nothing is invented when data or AI is missing.
declare(strict_types=1);

final class ConsultationService
{
    private array $config;
    private AIProvider $provider;

    public function __construct(
        ?AIProvider $provider = null,
        private ConversationService $conversations = new ConversationService(),
        private HealthContextService $healthContext = new HealthContextService(),
        private SymptomDetector $symptoms = new SymptomDetector(),
        private ?SpecialistRouter $router = null,
        private PromptBuilder $prompts = new PromptBuilder(),
        private MedicalSourcesService $sources = new MedicalSourcesService()
    ) {
        $this->config = ai_specialists_config();
        $this->provider = $provider ?? AIService::providerFromConfig();
        $this->router ??= new SpecialistRouter($this->config);
    }

    public function specialists(): array
    {
        $list = [];
        foreach ($this->config['specialists'] as $key => $specialist) {
            $list[] = [
                'key' => $key, 'name' => t("ai.specialist.{$key}.name"), 'focus' => t("ai.specialist.{$key}.focus"),
                'description' => t("ai.specialist.{$key}.description"), 'metrics' => $specialist['metrics'],
                'icon' => $specialist['icon'], 'tone' => $specialist['tone'],
            ];
        }
        return $list;
    }

    public function status(): array
    {
        return ['configured' => $this->provider->isConfigured(), 'provider' => $this->provider->name()];
    }

    // Opens (or reopens) a consultation. Specialists receive a structured handoff built from the
    // General Doctor consultation, so the astronaut never has to repeat themselves.
    public function open(array $user, string $specialist, ?int $referralFromId = null): array
    {
        if (!isset($this->config['specialists'][$specialist])) {
            throw ApiException::validation(['specialist' => t('err.unknown_specialist')]);
        }
        $userId = (int) $user['id'];

        $source = null;
        if ($referralFromId !== null) {
            $source = $this->conversations->get($userId, $referralFromId);   // ownership enforced
        } elseif ($specialist !== 'general') {
            $source = $this->conversations->latest($userId, 'general');
        }

        $existing = $this->conversations->activeFor($userId, $specialist);
        if ($existing !== null) {
            // Reopening after a new referral: pass on anything said since the last handoff.
            if ($specialist !== 'general' && $source !== null) {
                $lastSourceMessage = $this->conversations->lastMessage($source['id']);
                $handedOffUpTo = $existing['handoff']['created_from_message_id'] ?? 0;
                if ($lastSourceMessage && $lastSourceMessage['id'] > $handedOffUpTo) {
                    $handoff = $this->buildHandoff($userId, $specialist, $source);
                    db()->prepare('UPDATE conversations SET handoff = ? WHERE id = ?')->execute([json_encode($handoff, JSON_UNESCAPED_UNICODE), $existing['id']]);
                    $this->addHandoffMessage($existing['id'], $specialist, $handoff, true);
                }
            }
            return $this->load($user, $existing['id']);
        }

        $handoff = $specialist !== 'general' ? $this->buildHandoff($userId, $specialist, $source) : null;
        $conversation = $this->conversations->create($userId, $specialist, $source['id'] ?? null, $handoff);
        if ($handoff !== null) {
            $this->addHandoffMessage($conversation['id'], $specialist, $handoff, false);
        }
        return $this->load($user, $conversation['id']);
    }

    public function load(array $user, int $conversationId, int $afterId = 0): array
    {
        $conversation = $this->conversations->get((int) $user['id'], $conversationId);
        return ['conversation' => $conversation, 'messages' => $this->conversations->messages($conversationId, $afterId)];
    }

    public function sendMessage(array $user, int $conversationId, string $text): array
    {
        $conversation = $this->conversations->get((int) $user['id'], $conversationId);
        if ($conversation['status'] !== 'active') {
            throw new ApiException(409, 'consultation_closed', t('err.consultation_closed'));
        }

        $text = clean_multiline($text);
        if ($text === '') {
            throw ApiException::validation(['message' => t('err.message_empty')]);
        }
        if (mb_strlen($text) > 2000) {
            throw ApiException::validation(['message' => t('err.message_2000')]);
        }

        $userMessage = $this->conversations->addMessage($conversationId, 'user', $text);
        $reply = $this->reply($user, $conversation);
        return ['conversation' => $this->conversations->get((int) $user['id'], $conversationId), 'messages' => [$userMessage, $reply]];
    }

    // Runs the analysis again for the latest message (e.g. after an AI timeout).
    public function retry(array $user, int $conversationId): array
    {
        $conversation = $this->conversations->get((int) $user['id'], $conversationId);
        $last = $this->conversations->lastMessage($conversationId);
        if (!$last || ($last['sender'] === 'assistant' && empty($last['payload']['retryable']))) {
            throw new ApiException(409, 'nothing_to_retry', t('err.nothing_to_retry'));
        }
        return ['conversation' => $conversation, 'messages' => [$this->reply($user, $conversation)]];
    }

    // Used by the dashboard AI panel: talks to the General Doctor AI.
    public function sendToGeneralDoctor(array $user, string $text): array
    {
        $opened = $this->open($user, 'general');
        return $this->sendMessage($user, $opened['conversation']['id'], $text);
    }

    // ---------------------------------------------------------------- analysis

    private function reply(array $user, array $conversation): array
    {
        $userId = (int) $user['id'];
        $key = $conversation['specialist'];
        $specialist = $this->config['specialists'][$key];
        $handoff = $conversation['handoff'] ?? null;

        $allMetrics = array_keys($this->config['metric_specialists']);
        $context = $this->healthContext->build($userId, $allMetrics, $this->config['trend_window_seconds']);
        $focusMetrics = array_merge($specialist['metrics'], $specialist['context_metrics']);
        $focused = $this->focus($context, $focusMetrics);

        $userTexts = $this->conversations->userTexts($conversation['id']);
        if ($handoff && !empty($handoff['patient_concern'])) {
            array_unshift($userTexts, $handoff['patient_concern']);
        }
        $symptoms = $this->symptoms->detect($userTexts);
        $routing = $this->router->route($symptoms, $context);

        // A dangerous symptom in the LATEST message rings the crew alarm (EmergencyService).
        $latestText = $this->conversations->userTexts($conversation['id'], 1);
        $redFlags = array_keys(array_filter($this->symptoms->detect($latestText), fn ($s) => $s['red_flag']));
        $crewAlerted = $redFlags ? (new EmergencyService())->raiseForSymptoms($userId, $redFlags, (string) end($latestText)) > 0 : false;

        // A specialist only points elsewhere when another specialist is clearly more relevant.
        $recommendation = $routing['recommendation'];
        if ($key !== 'general' && ($recommendation === null || $recommendation['specialist'] === $key || $recommendation['confidence'] === 'low')) {
            $recommendation = null;
        }

        // Official sources (online, cached): MedlinePlus pages and the FDA labels of the astronaut's medications.
        $references = $this->sources->references(array_keys($symptoms));
        $medicationNotes = array_map(fn ($m) => [
            'text' => t('ai.medication_match', ['symptom' => t("ai.symptom.{$m['symptom']}"), 'medication' => $m['medication']]),
            'label_name' => $m['label_name'], 'url' => $m['url'],
        ], $this->sources->medicationMatches($userId, array_keys($symptoms)));

        $history = array_map(fn ($m) => ['sender' => $m['sender'], 'text' => $m['body'], 'at' => $m['created_at']], $this->conversations->messages($conversation['id'], 0, 30));
        $prompt = $this->prompts->build($specialist, $focused, $history, $this->previousConversations($userId, $conversation['id']), $handoff, [
            'detected_symptoms' => array_values(array_map(fn ($s) => $s['label'], $symptoms)),
            'recommendation' => $recommendation,
            'next_step' => $routing['next_step'],
            'official_sources' => [
                'medlineplus' => array_map(fn ($r) => ['title' => $r['title'], 'url' => $r['url'], 'summary' => $r['summary']], $references),
                'fda_label_matches' => array_column($medicationNotes, 'text'),
            ],
        ]);

        $result = $this->callProvider($prompt);
        $measurementIds = array_values(array_filter(array_column($focused['current'], 'raw_measurement_id')));
        $analysis = (new AIService($this->provider))->record($userId, 'consultation', $key, $measurementIds, null, $result);

        $payload = $this->payload($key, $specialist, $focused, $symptoms, $recommendation, $routing['next_step'], $result, $analysis['id']);
        $payload['references'] = $references;
        $payload['medication_notes'] = $medicationNotes;
        $payload['crew_alerted'] = $crewAlerted;
        $body = $result->ok() ? (string) $result->output['reply'] : $this->ruleBasedBody($payload);

        return $this->conversations->addMessage($conversation['id'], 'assistant', $body, $payload, $analysis['id']);
    }

    private function callProvider(array $prompt): AIResult
    {
        try {
            $result = $this->provider->run('consultation', $prompt);
        } catch (Throwable $error) {
            log_event('ai.provider_error', ['provider' => $this->provider->name(), 'status' => 'error', 'error' => $error->getMessage()], 'error');
            return AIResult::error(t('ai.error.no_response'), $this->provider->name());
        }

        if ($result->ok()) {
            $missing = array_diff(PromptBuilder::OUTPUT_FIELDS, array_keys($result->output));
            if ($missing || !is_string($result->output['reply']) || trim($result->output['reply']) === '') {
                log_event('ai.malformed_response', ['provider' => $result->provider, 'missing' => array_values($missing), 'status' => 'error'], 'warning');
                return AIResult::error(t('ai.error.incomplete'), $result->provider);
            }
        }
        return $result;
    }

    // Keeps only the metrics (and their alerts/trends) relevant to one specialist.
    private function focus(array $context, array $metrics): array
    {
        $keep = fn (array $items) => array_values(array_filter($items, fn ($item) => in_array($item['metric'], $metrics, true)));
        $context['current'] = $keep($context['current']);
        $context['trends'] = $keep($context['trends']);
        $context['alerts'] = $keep($context['alerts']);
        return $context;
    }

    private function payload(string $key, array $specialist, array $focused, array $symptoms, ?array $recommendation, array $nextStep, AIResult $result, int $analysisId): array
    {
        $interpretation = match ($result->status) {
            'success' => ['status' => 'success', 'text' => (string) $result->output['interpretation'], 'insufficient_data' => (bool) $result->output['insufficient_data']],
            'not_configured' => ['status' => 'not_configured', 'message' => t('ai.interpretation.not_configured')],
            default => ['status' => 'error', 'message' => $result->message ?? t('ai.interpretation.error')],
        };
        if ($result->ok() && !empty($result->output['recommended_next_step'])) {
            $nextStep['ai_text'] = (string) $result->output['recommended_next_step'];
        }

        $lead = t($focused['data_status'] === 'none' ? 'ai.lead.no_data' : 'ai.lead.with_data');
        if ($recommendation) {
            $lead .= ' ' . t('ai.lead.recommend', ['name' => $recommendation['name']]);
        }

        return [
            'kind' => 'consultation_reply',
            'specialist' => $key,
            'specialist_name' => t("ai.specialist.{$key}.name"),
            'lead' => $lead,
            'data_status' => $focused['data_status'],
            'data_last_updated' => $focused['data_last_updated'],
            'data_scope_note' => $this->scopeNote($specialist),
            'observations' => $focused['current'],
            'self_reported' => $this->describeSelfReports($focused['self_reports'] ?? null),
            'trends' => array_map([$this, 'describeTrend'], $focused['trends']),
            'patterns' => $focused['patterns'],
            'alerts' => array_map(fn ($a) => ['id' => $a['id'], 'title' => $a['title'], 'severity' => $a['severity']], $focused['alerts']),
            'symptoms' => array_values(array_map(fn ($s) => ['key' => $s['key'], 'label' => $s['label'], 'red_flag' => $s['red_flag']], $symptoms)),
            'interpretation' => $interpretation,
            'recommendation' => $recommendation,
            'next_step' => $nextStep,
            'follow_up_questions' => $result->ok() ? array_values(array_filter((array) $result->output['follow_up_questions'], 'is_string')) : [],
            // Concrete steps from the AI (optional field; only short strings are kept).
            'action_plan' => $result->ok()
                ? array_slice(array_values(array_filter((array) ($result->output['action_plan'] ?? []), fn ($s) => is_string($s) && trim($s) !== '' && mb_strlen($s) <= 400)), 0, 5)
                : [],
            'safety_notice' => t('ai.safety_notice'),
            'analysis_id' => $analysisId,
            'retryable' => $result->status === 'error',
        ];
    }

    private function describeTrend(array $trend): array
    {
        $text = t('ai.trend.text', [
            'mean' => $trend['mean'], 'unit' => unit_label($trend['unit']), 'n' => $trend['samples'],
            'min' => $trend['min'], 'max' => $trend['max'], 'direction' => t("ai.trend.direction.{$trend['direction']}"),
        ]);
        if ($trend['abnormal_samples'] > 0) {
            $text .= t('ai.trend.abnormal', ['percent' => (int) round($trend['abnormal_share'] * 100)]);
        }
        if ($trend['earlier_mean'] !== null) {
            $text .= t('ai.trend.earlier', ['mean' => $trend['earlier_mean']]);
        }
        return ['metric' => $trend['metric'], 'label' => $trend['label'], 'text' => $text . '.'];
    }

    // Plain lines describing what the astronaut reported in their daily check-ins.
    private function describeSelfReports(?array $reports): array
    {
        if (!$reports || (!$reports['days_recorded'] && empty($reports['latest_alertness_test']))) {
            return [];
        }
        $lines = [];
        if ($a = $reports['latest_alertness_test'] ?? null) {
            $lines[] = t('ai.self.alertness', [
                'median' => $a['median_reaction_time_ms'] ?? '—', 'lapses' => $a['lapses'],
                'status' => t("alertness.status.{$a['compared_with_own_baseline']}"),
            ]);
        }
        if ($t = $reports['today']) {
            $lines[] = t('ai.self.today', [
                'hours' => $t['sleep_hours'], 'quality' => $t['sleep_quality'], 'fatigue' => $t['fatigue'],
                'done' => $t['exercise_minutes'], 'target' => $t['exercise_target_minutes'],
            ]);
        }
        if ($reports['symptom_days_last_7']) {
            $parts = [];
            foreach ($reports['symptom_days_last_7'] as $label => $days) {
                $parts[] = t($days > 1 ? 'ai.self.symptom_days' : 'ai.self.symptom_day_one', ['label' => $label, 'days' => $days]);
            }
            $lines[] = t('ai.self.symptoms', ['list' => implode(t('common.list_separator'), $parts)]);
        }
        if ($w = $reports['latest_wellbeing_screen']) {
            $flag = $w['low_mood_flag'] && $w['anxiety_flag'] ? 'both' : ($w['low_mood_flag'] ? 'mood' : ($w['anxiety_flag'] ? 'anxiety' : null));
            $lines[] = t('ai.self.wellbeing', [
                'date' => $w['completed_on'], 'band' => t("checkin.band.{$w['band']}"),
                'flags' => $flag ? t("ai.self.flag.{$flag}") : '',
            ]);
        }
        return $lines;
    }

    private function scopeNote(array $specialist): ?string
    {
        if (!$specialist['metrics']) {
            return t('ai.scope.no_measurements');
        }
        return null;
    }

    // Plain-text version of a rule-based reply (also used by the dashboard AI panel).
    private function ruleBasedBody(array $payload): string
    {
        $parts = [$payload['lead']];
        if ($payload['symptoms']) {
            $labels = array_column($payload['symptoms'], 'label');
            $list = implode(t('common.list_separator'), $labels);
            $parts[] = t('ai.body.mentioned', ['list' => current_language() === 'en' ? strtolower($list) : $list]);
        }
        $readings = [];
        foreach ($payload['observations'] as $o) {
            if ($o['available']) {
                $readings[] = "{$o['label']} {$o['value']} " . unit_label($o['unit']) . ($o['classification'] !== 'GREEN' ? ' ' . t('ai.body.outside') : '');
            }
        }
        if ($readings) {
            $parts[] = t('ai.body.latest', ['list' => implode(t('common.list_separator'), $readings)]);
        }
        foreach ($payload['medication_notes'] ?? [] as $note) {
            $parts[] = $note['text'];
        }
        $parts[] = t('ai.body.next', ['text' => $payload['next_step']['text']]);
        $parts[] = $payload['interpretation']['status'] === 'not_configured'
            ? t('ai.body.ai_unavailable')
            : ($payload['interpretation']['status'] === 'error' ? $payload['interpretation']['message'] : '');
        return trim(implode("\n\n", array_filter($parts)));
    }

    // ---------------------------------------------------------------- handoff

    private function buildHandoff(int $userId, string $specialist, ?array $source): array
    {
        $config = $this->config['specialists'][$specialist];
        $context = $this->focus(
            $this->healthContext->build($userId, array_merge($config['metrics'], $config['context_metrics']), $this->config['trend_window_seconds']),
            array_merge($config['metrics'], $config['context_metrics'])
        );

        $texts = $source ? $this->conversations->userTexts($source['id']) : [];
        $symptoms = $this->symptoms->detect($texts);
        $lastAssistant = null;
        if ($source) {
            foreach (array_reverse($this->conversations->messages($source['id'], 0, 30)) as $message) {
                if ($message['sender'] === 'assistant' && isset($message['payload']['kind'])) {
                    $lastAssistant = $message;
                    break;
                }
            }
        }
        $sourceRecommendation = $lastAssistant['payload']['recommendation'] ?? null;
        $referred = $sourceRecommendation && $sourceRecommendation['specialist'] === $specialist;

        return [
            'from_conversation_id' => $source['id'] ?? null,
            'created_from_message_id' => $source ? ($this->conversations->lastMessage($source['id'])['id'] ?? 0) : 0,
            'patient_concern' => $texts ? implode(' ', array_slice($texts, -3)) : null,
            'symptoms' => array_values(array_map(fn ($s) => $s['label'], $symptoms)),
            'relevant_measurements' => $context['current'],
            'relevant_trends' => array_map([$this, 'describeTrend'], $context['trends']),
            'relevant_alerts' => array_map(fn ($a) => ['id' => $a['id'], 'title' => $a['title'], 'severity' => $a['severity']], $context['alerts']),
            'general_doctor_assessment' => $referred ? $sourceRecommendation['reasons'] : [],
            'reason_for_referral' => $referred
                ? t('ai.handoff.recommended', ['confidence' => t("ai.confidence.{$sourceRecommendation['confidence']}")])
                : t($source ? 'ai.handoff.from_general' : 'ai.handoff.direct'),
            'data_last_updated' => $context['data_last_updated'],
            'data_status' => $context['data_status'],
        ];
    }

    private function addHandoffMessage(int $conversationId, string $specialist, array $handoff, bool $isUpdate): void
    {
        $config = $this->config['specialists'][$specialist];

        $name = t("ai.specialist.{$specialist}.name");
        $questions = $config['intake_questions']
            ? array_map(fn ($number) => t("ai.specialist.{$specialist}.intake.{$number}"), range(1, count($config['intake_questions'])))
            : [];
        $lines = [$isUpdate
            ? t('ai.handoff.update')
            : t('ai.handoff.hello', ['name' => $name]) . ' ' . t($handoff['from_conversation_id'] ? 'ai.handoff.shared' : 'ai.handoff.tell_me')];
        if ($handoff['patient_concern']) {
            $lines[] = t('ai.handoff.concern', ['text' => $handoff['patient_concern']]);
        }
        if ($questions) {
            $lines[] = t('ai.handoff.questions') . "\n- " . implode("\n- ", $questions);
        }

        $payload = [
            'kind' => $isUpdate ? 'handoff_update' : 'handoff',
            'specialist' => $specialist,
            'specialist_name' => $name,
            'lead' => $lines[0],
            'handoff' => $handoff,
            'data_status' => $handoff['data_status'],
            'data_last_updated' => $handoff['data_last_updated'],
            'data_scope_note' => $this->scopeNote($config),
            'observations' => $handoff['relevant_measurements'],
            'trends' => $handoff['relevant_trends'],
            'follow_up_questions' => $questions,
            'safety_notice' => t('ai.safety_notice'),
        ];
        $this->conversations->addMessage($conversationId, 'assistant', implode("\n\n", $lines), $payload);
    }

    // The last AI answer of an earlier consultation (shortened), so advice can build on it.
    private function lastAdvice(int $conversationId): ?string
    {
        foreach (array_reverse($this->conversations->messages($conversationId, 0, 6)) as $message) {
            if ($message['sender'] === 'assistant') {
                $text = trim(preg_replace('/\s+/', ' ', $message['body']));
                return mb_strlen($text) > 400 ? mb_substr($text, 0, 397) . '…' : $text;
            }
        }
        return null;
    }

    // Short summaries of the astronaut's other recent consultations (their own only).
    private function previousConversations(int $userId, int $currentId): array
    {
        $previous = [];
        foreach ($this->conversations->list($userId, 4) as $conversation) {
            if ($conversation['id'] === $currentId || count($previous) >= 3) {
                continue;
            }
            $previous[] = [
                'consultation_id' => $conversation['id'],
                'specialist' => $conversation['specialist_name'],
                'status' => $conversation['status'],
                'last_activity' => $conversation['updated_at'],
                'astronaut_said' => array_slice($this->conversations->userTexts($conversation['id'], 3), -3),
                'ai_last_advice' => $this->lastAdvice($conversation['id']),
            ];
        }
        return $previous;
    }
}
