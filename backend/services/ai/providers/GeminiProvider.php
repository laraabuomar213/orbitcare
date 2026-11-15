<?php
// Google Gemini (Gemini API, generateContent). Server-side only: the key comes from AI_API_KEY in
// .env and is sent in a header, never to the browser.
//
// Used when a link to the ground is available. Every failure (no link, timeout, quota, bad key,
// blocked or malformed answer) becomes an AIResult error, and OrbitCare falls back to its own
// rule-based analysis — the astronaut always gets an answer.
//
// Privacy: on Google's FREE tier, prompts may be used to improve Google's products and may be
// read by reviewers. OrbitCare therefore sends no names or astronaut IDs (PromptBuilder), and the
// demo uses public recorded data. Use a paid key before real crew data is involved.
declare(strict_types=1);

final class GeminiProvider implements AIProvider
{
    /** @var callable(string $url, array $headers, string $body, int $timeout): array{status:int, body:string|false, error:string} */
    private $transport;

    public function __construct(private array $settings, ?callable $transport = null)
    {
        $this->transport = $transport ?? [self::class, 'curlTransport'];
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function isConfigured(): bool
    {
        return trim($this->settings['api_key'] ?? '') !== '' && trim($this->settings['model'] ?? '') !== '';
    }

    public function run(string $task, array $input): AIResult
    {
        if (!$this->isConfigured()) {
            return AIResult::notConfigured();
        }
        $schema = $input['output_schema'] ?? null;
        unset($input['output_schema']);
        [$system, $prompt, $fields] = $this->messages($task, $input);

        $url = rtrim($this->settings['base_url'] ?: 'https://generativelanguage.googleapis.com/v1beta', '/')
            . '/models/' . rawurlencode($this->settings['model']) . ':generateContent';
        // An exact reply shape (responseSchema) when the caller gives one, so lists stay lists.
        $generation = ['responseMimeType' => 'application/json', 'temperature' => 0.3] + ($schema ? ['responseSchema' => $schema] : []);
        $body = json_encode([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => $generation,
        ], JSON_UNESCAPED_UNICODE);

        $started = microtime(true);
        $response = ($this->transport)($url, ['Content-Type: application/json', 'x-goog-api-key: ' . $this->settings['api_key']], $body, (int) $this->settings['timeout_seconds']);
        // Logged without any prompt or answer text.
        $log = ['provider' => 'gemini', 'task' => $task, 'http_status' => $response['status'], 'ms' => (int) ((microtime(true) - $started) * 1000)];

        if ($response['body'] === false || $response['status'] === 0) {
            log_event('ai.provider_request', $log + ['status' => 'no_link', 'error' => $response['error']], 'warning');
            return AIResult::error(t('ai.error.offline'), 'gemini');
        }
        if ($response['status'] !== 200) {
            log_event('ai.provider_request', $log + ['status' => 'http_error'], 'warning');
            $message = match (true) {
                $response['status'] === 429 => t('ai.error.quota'),
                in_array($response['status'], [400, 401, 403], true) && str_contains((string) $response['body'], 'API_KEY') => t('ai.error.key'),
                $response['status'] === 404 => t('ai.error.model'),
                default => t('ai.error.no_response'),
            };
            return AIResult::error($message, 'gemini');
        }

        $data = json_decode((string) $response['body'], true);
        $candidate = $data['candidates'][0] ?? null;
        $text = implode('', array_column($candidate['content']['parts'] ?? [], 'text'));
        if (($candidate['finishReason'] ?? '') === 'SAFETY' || $text === '') {
            log_event('ai.provider_request', $log + ['status' => 'empty_or_blocked'], 'warning');
            return AIResult::error(t('ai.error.incomplete'), 'gemini');
        }
        $output = json_decode(self::stripFence($text), true);
        if (!is_array($output) || ($fields && array_diff($fields, array_keys($output)))) {
            log_event('ai.provider_request', $log + ['status' => 'malformed'], 'warning');
            return AIResult::error(t('ai.error.incomplete'), 'gemini');
        }

        log_event('ai.provider_request', $log + ['status' => 'success']);
        return new AIResult('success', $output, null, 'gemini');
    }

    // Consultations arrive as a ready prompt (PromptBuilder); agents arrive as structured data.
    private function messages(string $task, array $input): array
    {
        if (isset($input['system'], $input['prompt'])) {
            return [$input['system'], $input['prompt'], $input['output_fields'] ?? []];
        }
        $fields = $input['output_fields'] ?? [];
        $system = ($input['instructions'] ?? '') . "\nYou are part of OrbitCare, a health-monitoring and decision-support system for astronauts. "
            . 'Reply in ' . t('ai.prompt.language') . '. Return only JSON'
            . ($fields ? ' with exactly these fields: ' . implode(', ', $fields) . '.' : '.');
        unset($input['instructions'], $input['output_fields']);
        $prompt = "TASK: {$task}\nDATA:\n" . json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return [$system, $prompt, $fields];
    }

    private static function stripFence(string $text): string
    {
        return trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)));
    }

    public static function curlTransport(string $url, array $headers, string $body, int $timeout): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $response = curl_exec($curl);
        $result = ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $response, 'error' => curl_error($curl)];
        curl_close($curl);
        return $result;
    }
}
