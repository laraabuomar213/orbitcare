<?php
// Gateway for a future external medical API. Handles authentication, request formatting,
// response validation, timeouts, error handling and logging in one place.
// The key comes from MEDICAL_API_KEY (environment / secret manager) and never leaves the server.
declare(strict_types=1);

final class MedicalApiException extends RuntimeException
{
}

final class MedicalApiService
{
    private array $settings;

    public function __construct(?array $settings = null)
    {
        $this->settings = $settings ?? config()['medical_api'];
    }

    public function isConfigured(): bool
    {
        return $this->settings['api_key'] !== '' && filter_var($this->settings['base_url'], FILTER_VALIDATE_URL) !== false;
    }

    public function status(): array
    {
        return ['configured' => $this->isConfigured(), 'message' => $this->isConfigured() ? 'Medical API connected.' : 'Medical API not configured'];
    }

    /**
     * Sends a JSON request and returns the decoded JSON response.
     * @param array $requiredFields fields the response must contain, or it is rejected as malformed
     */
    public function request(string $method, string $path, array $payload = [], array $requiredFields = [], ?int $userId = null): array
    {
        if (!$this->isConfigured()) {
            throw new MedicalApiException('Medical API not configured');
        }

        $url = rtrim($this->settings['base_url'], '/') . '/' . ltrim($path, '/');
        $started = microtime(true);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->settings['timeout_seconds'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $this->settings['api_key']],
            CURLOPT_POSTFIELDS     => $payload ? json_encode($payload) : null,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        $logContext = ['path' => $path, 'http_status' => $status, 'ms' => (int) ((microtime(true) - $started) * 1000)];
        audit('medical_api.request', $userId, 'medical_api', $path, ['http_status' => $status]);

        if ($body === false) {
            log_event('medical_api.request', $logContext + ['status' => 'network_error', 'error' => $curlError], 'error');
            throw new MedicalApiException('The medical service could not be reached (network error or timeout).');
        }
        if ($status < 200 || $status >= 300) {
            log_event('medical_api.request', $logContext + ['status' => 'http_error'], 'error');
            throw new MedicalApiException("The medical service returned an error (HTTP {$status}).");
        }

        $data = json_decode($body, true);
        if (!is_array($data) || array_diff($requiredFields, array_keys($data))) {
            log_event('medical_api.request', $logContext + ['status' => 'malformed_response'], 'error');
            throw new MedicalApiException('The medical service returned an unexpected response.');
        }

        log_event('medical_api.request', $logContext + ['status' => 'success']);
        return $data;
    }
}
