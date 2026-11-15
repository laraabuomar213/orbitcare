<?php
// Daily check-in (self-reported indicators) and the weekly PHQ-4 wellbeing check.
// One check-in per astronaut per mission day (UTC); saving again the same day updates it.
// Everything stored is exactly what the astronaut entered; nothing is filled in for missing days.
declare(strict_types=1);

final class CheckinService
{
    private array $config;

    public function __construct(?array $config = null, private ?CheckinAnalysis $analysis = null)
    {
        $this->config = $config ?? checkin_config();
        $this->analysis ??= new CheckinAnalysis($this->config);
    }

    public function missionDay(): string
    {
        return gmdate('Y-m-d');
    }

    // Everything the check-in page needs in one call.
    public function overview(int $userId): array
    {
        $today = $this->missionDay();
        $recent = $this->recent($userId, 7);
        $wellbeing = $this->wellbeingStatus($userId);

        return [
            'mission_day' => $today,
            'checkin' => $recent[$today] ?? null,
            'wellbeing' => $wellbeing,
            'trends' => $this->trends($recent, 7),
            'radiation' => $this->radiationTotal($userId),
            'insights' => $this->analysis->insights($recent, $wellbeing['last'], $today),
            'form' => $this->formDefinition(),
        ];
    }

    public function save(int $userId, array $body): array
    {
        $errors = [];
        $sleepHours = $this->number($body, 'sleep_hours', 0, 24, $errors, t('err.checkin.sleep_hours'), 0.5);
        $sleepQuality = $this->choice($body, 'sleep_quality', array_keys($this->config['sleep_quality']), $errors, t('err.checkin.sleep_quality'));
        $fatigue = $this->choice($body, 'fatigue_level', array_keys($this->config['fatigue']), $errors, t('err.checkin.fatigue'));
        $resistance = $this->number($body, 'resistance_minutes', 0, 600, $errors, t('err.checkin.resistance'), 1);
        $aerobic = $this->number($body, 'aerobic_minutes', 0, 600, $errors, t('err.checkin.aerobic'), 1);

        $symptoms = is_array($body['symptoms'] ?? null) ? array_values(array_unique(array_filter($body['symptoms'], 'is_string'))) : [];
        $allowed = array_merge(array_keys($this->config['symptoms']), ['none']);
        if (!$symptoms) {
            $errors['symptoms'] = t('err.checkin.symptoms');
        } elseif (array_diff($symptoms, $allowed)) {
            $errors['symptoms'] = t('err.checkin.unknown_symptom');
        } elseif (in_array('none', $symptoms, true) && count($symptoms) > 1) {
            $errors['symptoms'] = '"None" cannot be combined with other symptoms.';
        }

        $radiation = null;
        if (($body['radiation_dose_msv'] ?? '') !== '' && ($body['radiation_dose_msv'] ?? null) !== null) {
            $radiation = $this->number($body, 'radiation_dose_msv', 0, 1000, $errors, t('err.checkin.radiation'), 0.001);
        }

        $notes = clean_multiline($body['notes'] ?? '');
        if (mb_strlen($notes) > 500) {
            $errors['notes'] = t('err.checkin.notes');
        }

        $wellbeingAnswers = null;
        if (!empty($body['wellbeing']) && is_array($body['wellbeing'])) {
            $wellbeingAnswers = $this->validateWellbeing($body['wellbeing'], $errors);
        }

        if ($errors) {
            throw ApiException::validation($errors);
        }

        $today = $this->missionDay();
        db_transaction(function (PDO $pdo) use ($userId, $today, $sleepHours, $sleepQuality, $fatigue, $resistance, $aerobic, $radiation, $notes, $symptoms, $wellbeingAnswers) {
            $pdo->prepare(
                'INSERT INTO daily_checkins
                    (user_id, checkin_date, sleep_hours, sleep_quality, fatigue_level, resistance_minutes, aerobic_minutes, radiation_dose_msv, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE sleep_hours = VALUES(sleep_hours), sleep_quality = VALUES(sleep_quality),
                    fatigue_level = VALUES(fatigue_level), resistance_minutes = VALUES(resistance_minutes),
                    aerobic_minutes = VALUES(aerobic_minutes), radiation_dose_msv = VALUES(radiation_dose_msv), notes = VALUES(notes)'
            )->execute([$userId, $today, $sleepHours, $sleepQuality, $fatigue, (int) $resistance, (int) $aerobic, $radiation, $notes !== '' ? $notes : null]);

            $id = $pdo->prepare('SELECT id FROM daily_checkins WHERE user_id = ? AND checkin_date = ?');
            $id->execute([$userId, $today]);
            $checkinId = (int) $id->fetchColumn();

            $pdo->prepare('DELETE FROM checkin_symptoms WHERE checkin_id = ?')->execute([$checkinId]);
            $insert = $pdo->prepare('INSERT INTO checkin_symptoms (checkin_id, symptom) VALUES (?, ?)');
            foreach ($symptoms as $symptom) {
                $insert->execute([$checkinId, $symptom]);
            }

            if ($wellbeingAnswers !== null) {
                $this->storeWellbeing($pdo, $userId, $today, $wellbeingAnswers);
            }
        });

        audit('checkin.save', $userId, 'daily_checkin', $today, ['wellbeing' => $wellbeingAnswers !== null]);
        log_event('checkin.saved', ['user_id' => $userId, 'date' => $today, 'status' => 'success']);
        return $this->overview($userId);
    }

    // ---------- Weekly PHQ-4 ----------

    public function wellbeingStatus(int $userId): array
    {
        $statement = db()->prepare('SELECT * FROM wellbeing_checks WHERE user_id = ? ORDER BY completed_on DESC LIMIT 1');
        $statement->execute([$userId]);
        $row = $statement->fetch();

        $repeatDays = $this->config['phq4']['repeat_after_days'];
        $last = $row ? $this->scoreSummary($row) : null;
        $nextDue = $row ? gmdate('Y-m-d', strtotime($row['completed_on'] . " +{$repeatDays} days")) : $this->missionDay();

        return ['due' => $nextDue <= $this->missionDay(), 'next_due_on' => $nextDue, 'last' => $last];
    }

    private function validateWellbeing(array $answers, array &$errors): ?array
    {
        $clean = [];
        foreach (array_keys($this->config['phq4']['items']) as $item) {
            $value = $answers[$item] ?? $answers[(string) $item] ?? null;
            if (!is_numeric($value) || !in_array((int) $value, array_keys($this->config['phq4']['options']), true)) {
                $errors['wellbeing'] = t('err.checkin.wellbeing');
                return null;
            }
            $clean[$item] = (int) $value;
        }
        return $clean;
    }

    private function storeWellbeing(PDO $pdo, int $userId, string $date, array $answers): void
    {
        $depression = $answers[1] + $answers[2];
        $anxiety = $answers[3] + $answers[4];
        $pdo->prepare(
            'INSERT INTO wellbeing_checks (user_id, completed_on, item_1, item_2, item_3, item_4, depression_score, anxiety_score, total_score)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE item_1 = VALUES(item_1), item_2 = VALUES(item_2), item_3 = VALUES(item_3), item_4 = VALUES(item_4),
                depression_score = VALUES(depression_score), anxiety_score = VALUES(anxiety_score), total_score = VALUES(total_score)'
        )->execute([$userId, $date, $answers[1], $answers[2], $answers[3], $answers[4], $depression, $anxiety, $depression + $anxiety]);
        audit('wellbeing.save', $userId, 'wellbeing_check', $date);
    }

    private function scoreSummary(array $row): array
    {
        $total = (int) $row['total_score'];
        $band = 'normal';
        foreach ($this->config['phq4']['bands'] as [$min, $max, $name]) {
            if ($total >= $min && $total <= $max) {
                $band = $name;
            }
        }
        $cutoff = $this->config['phq4']['subscale_cutoff'];
        return [
            'completed_on' => $row['completed_on'],
            'total_score' => $total,
            'band' => $band,
            'depression_score' => (int) $row['depression_score'],
            'anxiety_score' => (int) $row['anxiety_score'],
            'depression_positive' => (int) $row['depression_score'] >= $cutoff,
            'anxiety_positive' => (int) $row['anxiety_score'] >= $cutoff,
        ];
    }

    // ---------- History ----------

    // Check-ins of the last N mission days, keyed by date (missing days are simply absent).
    public function recent(int $userId, int $days): array
    {
        $statement = db()->prepare(
            "SELECT c.*, GROUP_CONCAT(s.symptom ORDER BY s.symptom) AS symptom_list
             FROM daily_checkins c LEFT JOIN checkin_symptoms s ON s.checkin_id = c.id
             WHERE c.user_id = ? AND c.checkin_date > UTC_DATE() - INTERVAL ? DAY
             GROUP BY c.id ORDER BY c.checkin_date DESC"
        );
        $statement->execute([$userId, $days]);

        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[$row['checkin_date']] = [
                'date' => $row['checkin_date'],
                'sleep_hours' => (float) $row['sleep_hours'],
                'sleep_quality' => (int) $row['sleep_quality'],
                'fatigue_level' => (int) $row['fatigue_level'],
                'resistance_minutes' => (int) $row['resistance_minutes'],
                'aerobic_minutes' => (int) $row['aerobic_minutes'],
                'exercise_minutes' => (int) $row['resistance_minutes'] + (int) $row['aerobic_minutes'],
                'radiation_dose_msv' => $row['radiation_dose_msv'] === null ? null : (float) $row['radiation_dose_msv'],
                'notes' => $row['notes'],
                'symptoms' => $row['symptom_list'] ? explode(',', $row['symptom_list']) : [],
                'saved_at' => iso_time($row['updated_at']),
            ];
        }
        return $rows;
    }

    // One entry per day; days without a check-in stay null (never interpolated).
    private function trends(array $recent, int $days): array
    {
        $series = [];
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = gmdate('Y-m-d', strtotime("-{$offset} days"));
            $day = $recent[$date] ?? null;
            $series[] = [
                'date' => $date,
                'sleep_hours' => $day['sleep_hours'] ?? null,
                'fatigue_level' => $day['fatigue_level'] ?? null,
                'exercise_minutes' => $day['exercise_minutes'] ?? null,
            ];
        }
        return $series;
    }

    private function radiationTotal(int $userId): array
    {
        $statement = db()->prepare('SELECT COUNT(radiation_dose_msv) AS readings, SUM(radiation_dose_msv) AS total FROM daily_checkins WHERE user_id = ?');
        $statement->execute([$userId]);
        $row = $statement->fetch();
        return ['readings' => (int) $row['readings'], 'total_msv' => $row['total'] === null ? null : round((float) $row['total'], 3)];
    }

    private function formDefinition(): array
    {
        // Labels in the reader's language; validated instruments also keep their English original.
        $labels = fn (array $values, string $prefix, string $language) => array_combine(
            array_keys($values),
            array_map(fn ($key) => translate_in($language, "{$prefix}.{$key}"), array_keys($values))
        );
        $phq = $this->config['phq4'];
        return [
            'sleep_quality' => $labels($this->config['sleep_quality'], 'checkin.sleep_quality', current_language()),
            'fatigue' => $labels($this->config['fatigue'], 'checkin.fatigue', current_language()),
            'fatigue_original' => $this->config['fatigue'],
            'exercise_target_minutes' => $this->config['exercise_target_minutes'],
            'symptoms' => $labels($this->config['symptoms'], 'checkin.symptom', current_language()),
            'phq4' => [
                'stem' => t('checkin.phq.stem'),
                'items' => $labels($phq['items'], 'checkin.phq.item', current_language()),
                'options' => $labels($phq['options'], 'checkin.phq.option', current_language()),
                'original' => ['stem' => $phq['stem'], 'items' => $phq['items'], 'options' => $phq['options']],
            ],
            'sources' => array_map(fn ($key) => checkin_source($this->config, $key), array_combine(array_keys($this->config['sources']), array_keys($this->config['sources']))),
        ];
    }

    // ---------- Validation helpers ----------

    private function number(array $body, string $field, float $min, float $max, array &$errors, string $message, float $step): ?float
    {
        $raw = $body[$field] ?? null;
        if (!is_numeric($raw) || (float) $raw < $min || (float) $raw > $max) {
            $errors[$field] = $message;
            return null;
        }
        return round((float) $raw / $step) * $step;
    }

    private function choice(array $body, string $field, array $allowed, array &$errors, string $message): ?int
    {
        $raw = $body[$field] ?? null;
        if (!is_numeric($raw) || !in_array((int) $raw, $allowed, true)) {
            $errors[$field] = $message;
            return null;
        }
        return (int) $raw;
    }
}
