<?php
// Official medical sources next to the AI (config/medical-sources.php):
//   - MedlinePlus (NLM): a trusted explanation page for each symptom the astronaut reported.
//   - openFDA (FDA): the official label of each medication in the astronaut's profile, to check
//     whether a reported symptom is listed among its warnings or side effects.
//
// Online only. Answers are cached for 24 h (NLM asks for 12–24 h), so they stay available during
// short losses of signal. When the link is down, the first failed request stops all further ones
// in the same request, so a reply is never delayed by several timeouts. Nothing here is a
// diagnosis: matches are reported as "listed in the official label", with the source link.
declare(strict_types=1);

final class MedicalSourcesService
{
    private static bool $linkDown = false;     // per request: stop calling out after a network failure

    private array $config;
    private array $settings;
    /** @var callable(string $url, int $timeout): array{status:int, body:string|false} */
    private $fetch;

    public function __construct(?array $config = null, ?array $settings = null, ?callable $fetch = null)
    {
        $this->config = $config ?? require dirname(__DIR__, 2) . '/config/medical-sources.php';
        $this->settings = $settings ?? config()['medical_sources'];
        $this->fetch = $fetch ?? [self::class, 'httpGet'];
    }

    public static function resetLinkState(): void
    {
        self::$linkDown = false;
    }

    /**
     * MedlinePlus pages for detected symptoms (keys from SymptomDetector), at most max_references.
     * @return array<int, array{symptom: string, title: string, url: string, summary: string}>
     */
    public function references(array $symptomKeys): array
    {
        $references = [];
        foreach (array_unique($symptomKeys) as $key) {
            if (count($references) >= $this->config['max_references']) {
                break;
            }
            $term = $this->config['symptoms'][$key]['term'] ?? null;
            if ($term === null) {
                continue;
            }
            $page = $this->cached("medlineplus:{$term}", fn () => $this->fetchMedlinePlus($term));
            // Several symptoms can point to the same page (e.g. chest pain / tightness): show it once.
            if ($page && $page['found'] && !in_array($page['url'], array_column($references, 'url'), true)) {
                $references[] = ['symptom' => $key, 'title' => $page['title'], 'url' => $page['url'], 'summary' => $page['summary']];
            }
        }
        return $references;
    }

    /**
     * Reported symptoms that appear in the official FDA label of one of the astronaut's medications.
     * @return array<int, array{symptom: string, medication: string, label_name: string, url: string}>
     */
    public function medicationMatches(int $userId, array $symptomKeys): array
    {
        $symptomKeys = array_values(array_filter(array_unique($symptomKeys), fn ($k) => isset($this->config['symptoms'][$k])));
        if (!$symptomKeys) {
            return [];
        }
        $statement = db()->prepare('SELECT name FROM astronaut_medications WHERE user_id = ? ORDER BY id LIMIT 10');
        $statement->execute([$userId]);

        $matches = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $medication) {
            $name = mb_strtolower(trim($medication));
            if ($name === '' || !preg_match('/^[\p{L}\p{N} .\-]{2,80}$/u', $name)) {
                continue;
            }
            $label = $this->cached("openfda:{$name}", fn () => $this->fetchLabel($name));
            if (!$label || !$label['found']) {
                continue;
            }
            foreach ($symptomKeys as $key) {
                foreach ($this->config['symptoms'][$key]['label_terms'] as $word) {
                    if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $label['text'])) {
                        $matches[] = ['symptom' => $key, 'medication' => $medication, 'label_name' => $label['name'], 'url' => $label['url']];
                        break;
                    }
                }
            }
        }
        return $matches;
    }

    // ---------- Sources ----------

    private function fetchMedlinePlus(string $term): ?array
    {
        $response = $this->get('https://wsearch.nlm.nih.gov/ws/query?db=healthTopics&retmax=1&term=' . rawurlencode($term));
        if ($response === null) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($previous);
        $document = $xml ? ($xml->list->document[0] ?? null) : null;
        if (!$document || !str_starts_with((string) $document['url'], 'https://medlineplus.gov/')) {
            return ['found' => false];
        }
        $field = function (string $name) use ($document): string {
            foreach ($document->content as $content) {
                if ((string) $content['name'] === $name) {
                    // Tags become spaces, so "…mixed up.</p><p>Dizziness…" keeps its sentence break.
                    $text = preg_replace('/<[^>]*>/', ' ', html_entity_decode((string) $content, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    return trim(preg_replace('/\s+([.,;:?!])/', '$1', preg_replace('/\s+/', ' ', $text)));
                }
            }
            return '';
        };
        $summary = $field('FullSummary');
        return [
            'found' => true,
            'title' => $field('title'),
            'url' => (string) $document['url'],
            'summary' => mb_strlen($summary) > 220 ? rtrim(mb_substr($summary, 0, 217)) . '…' : $summary,
        ];
    }

    private function fetchLabel(string $name): ?array
    {
        $quoted = '"' . str_replace('"', '', $name) . '"';
        $url = 'https://api.fda.gov/drug/label.json?limit=1&search='
            . rawurlencode("openfda.generic_name:{$quoted} openfda.brand_name:{$quoted}");
        if ($this->settings['openfda_api_key'] !== '') {
            $url .= '&api_key=' . rawurlencode($this->settings['openfda_api_key']);
        }
        $response = $this->get($url, [404]);   // openFDA answers 404 when nothing matches
        if ($response === null) {
            return null;
        }
        $label = json_decode($response, true)['results'][0] ?? null;
        if (!$label || empty($label['set_id'])) {
            return ['found' => false];
        }
        $text = '';
        foreach ($this->config['label_sections'] as $section) {
            $text .= ' ' . implode(' ', (array) ($label[$section] ?? []));
        }
        return [
            'found' => true,
            'name' => ucwords(mb_strtolower($label['openfda']['generic_name'][0] ?? $label['openfda']['brand_name'][0] ?? $name)),
            // DailyMed (NLM) shows the same label to people; set_id identifies it.
            'url' => 'https://dailymed.nlm.nih.gov/dailymed/drugInfo.cfm?setid=' . rawurlencode($label['set_id']),
            'text' => mb_substr(mb_strtolower(preg_replace('/\s+/', ' ', $text)), 0, 30000),
        ];
    }

    // ---------- Cache and HTTP ----------

    private function cached(string $key, callable $load): ?array
    {
        $key = mb_substr($key, 0, 191);
        $statement = db()->prepare('SELECT payload, fetched_at FROM medical_source_cache WHERE cache_key = ?');
        $statement->execute([$key]);
        $row = $statement->fetch();
        $fresh = $row && strtotime($row['fetched_at'] . ' UTC') > time() - $this->settings['cache_hours'] * 3600;
        if ($fresh) {
            return json_decode($row['payload'], true);
        }
        $value = $this->settings['enabled'] ? $load() : null;
        if ($value === null) {
            // Offline or failed: an older cached answer is still better than nothing.
            return $row ? json_decode($row['payload'], true) : null;
        }
        db()->prepare('INSERT INTO medical_source_cache (cache_key, payload, fetched_at) VALUES (?, ?, UTC_TIMESTAMP())
                       ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = VALUES(fetched_at)')
            ->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
        return $value;
    }

    // Body of a successful (or explicitly accepted) response, or null when unavailable.
    private function get(string $url, array $acceptedErrors = []): ?string
    {
        if (self::$linkDown) {
            return null;
        }
        $started = microtime(true);
        $response = ($this->fetch)($url, (int) $this->settings['timeout_seconds']);
        $host = parse_url($url, PHP_URL_HOST);
        $log = ['host' => $host, 'http_status' => $response['status'], 'ms' => (int) ((microtime(true) - $started) * 1000)];
        if ($response['body'] === false || $response['status'] === 0) {
            self::$linkDown = true;
            log_event('medical_sources.request', $log + ['status' => 'no_link'], 'warning');
            return null;
        }
        if ($response['status'] !== 200 && !in_array($response['status'], $acceptedErrors, true)) {
            log_event('medical_sources.request', $log + ['status' => 'http_error'], 'warning');
            return null;
        }
        log_event('medical_sources.request', $log + ['status' => 'success']);
        return $response['status'] === 200 ? (string) $response['body'] : '{}';
    }

    public static function httpGet(string $url, int $timeout): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['Accept: application/json, application/xml'], CURLOPT_USERAGENT => 'OrbitCare/1.0',
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return ['status' => $status, 'body' => $body];
    }
}
