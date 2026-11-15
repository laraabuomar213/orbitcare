<?php
// Checks the online AI and the medical sources, without sending any astronaut data:
//   php cli/check-ai.php
// - Gemini: lists the models this key can use and sends one tiny test request to AI_MODEL.
// - MedlinePlus / openFDA: one public lookup each.
// The API key is never printed.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../backend/bootstrap.php';

$ai = config()['ai'];
echo "AI provider: " . ($ai['provider'] ?: '(none)') . "\n";

if ($ai['provider'] !== 'gemini') {
    echo "Set AI_PROVIDER=gemini and AI_API_KEY=<your key> in .env (key from https://aistudio.google.com/apikey).\n";
} elseif (trim($ai['api_key']) === '') {
    echo "AI_API_KEY is empty in .env.\n";
} else {
    $base = rtrim($ai['base_url'] ?: 'https://generativelanguage.googleapis.com/v1beta', '/');
    $curl = curl_init("{$base}/models?pageSize=200");
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $ai['api_key']]]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($body === false || $status === 0) {
        echo "No connection to Google — check the internet link.\n";
    } elseif ($status !== 200) {
        echo "Google refused the key (HTTP {$status}). Check AI_API_KEY.\n";
    } else {
        $models = array_values(array_filter(json_decode($body, true)['models'] ?? [], fn ($m) => in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true)));
        $names = array_map(fn ($m) => preg_replace('#^models/#', '', $m['name']), $models);
        $flash = array_values(array_filter($names, fn ($n) => str_contains($n, 'flash')));
        echo "Key OK. Text models with 'flash' in the name:\n  " . implode("\n  ", $flash ?: $names) . "\n";
        echo "AI_MODEL is: {$ai['model']}" . (in_array($ai['model'], $names, true) ? " (listed)\n" : " (not in the list — aliases like *-latest may still work)\n");

        $result = (new GeminiProvider($ai))->run('connection_test', [
            'instructions' => 'This is a connection test.',
            'output_fields' => ['ok'],
            'request' => 'Return {"ok": true}.',
        ]);
        echo $result->ok() ? "Test request: OK — the AI is ready.\n" : "Test request failed: {$result->message}\n";
    }
}

echo "\nMedical sources:\n";
$sources = new MedicalSourcesService();
$references = $sources->references(['headache']);
echo '  MedlinePlus: ' . ($references ? "OK ({$references[0]['title']})" : 'not reachable') . "\n";
$fetch = new ReflectionMethod(MedicalSourcesService::class, 'fetchLabel');
$label = $fetch->invoke($sources, 'ibuprofen');
echo '  openFDA:     ' . ($label === null ? 'not reachable' : ($label['found'] ? "OK ({$label['name']} label)" : 'reachable, no label found')) . "\n";
echo '  openFDA key: ' . (config()['medical_sources']['openfda_api_key'] !== '' ? 'set' : 'not set (1,000 requests/day without a key — enough for the demo)') . "\n";
