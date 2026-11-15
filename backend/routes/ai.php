<?php
// /api/ai/* — AI Assistant (General Doctor AI + specialists), astronauts only.
// The user is always the signed-in astronaut from the session; IDs from the browser are
// only used to look up consultations, and ownership is checked for every one of them.
declare(strict_types=1);

return function (Router $router): void {
    $astronaut = ['roles' => ['astronaut']];

    $router->get('/ai/status', function (): array {
        return (new AIService())->status() + ['medical_api' => (new MedicalApiService())->status()];
    }, ['roles' => ['astronaut', 'mission_admin']]);

    $router->get('/ai/specialists', function (): array {
        return (new ConsultationService())->specialists();
    }, $astronaut);

    // ---------- Consultations ----------

    $router->get('/ai/conversations', function (array $params, array $body, array $user): array {
        return (new ConversationService())->list((int) $user['id'], (int) query_param('limit', '50'));
    }, $astronaut);

    // Opens a consultation (or returns the active one) with the chosen AI.
    // { specialist: general|respiratory|cardiology|neurology, referral_from?: <conversation id> }
    $router->post('/ai/conversations', function (array $params, array $body, array $user): array {
        $referral = $body['referral_from'] ?? null;
        if ($referral !== null && !(is_int($referral) || ctype_digit((string) $referral))) {
            throw ApiException::validation(['referral_from' => t('err.invalid_consultation')]);
        }
        return (new ConsultationService())->open($user, (string) ($body['specialist'] ?? ''), $referral === null ? null : (int) $referral);
    }, $astronaut + ['rate' => ['ai_open', 30, 60]]);

    $router->get('/ai/conversations/{id}', function (array $params, array $body, array $user): array {
        return (new ConsultationService())->load($user, $params['id'], (int) query_param('after_id', '0'));
    }, $astronaut);

    $router->post('/ai/conversations/{id}/messages', function (array $params, array $body, array $user): array {
        return (new ConsultationService())->sendMessage($user, $params['id'], (string) ($body['message'] ?? ''));
    }, $astronaut + ['rate' => ['ai_message', 20, 60]]);

    $router->post('/ai/conversations/{id}/retry', function (array $params, array $body, array $user): array {
        return (new ConsultationService())->retry($user, $params['id']);
    }, $astronaut + ['rate' => ['ai_message', 20, 60]]);

    $router->post('/ai/conversations/{id}/close', function (array $params, array $body, array $user): array {
        return (new ConversationService())->close((int) $user['id'], $params['id']);
    }, $astronaut);

    // ---------- Dashboard AI panel (talks to the General Doctor AI) ----------

    $router->get('/ai/conversation', function (array $params, array $body, array $user): array {
        $conversation = (new ConversationService())->activeFor((int) $user['id'], 'general');
        return $conversation ? (new ConversationService())->messages($conversation['id'], 0, 50) : [];
    }, $astronaut);

    $router->post('/ai/chat', function (array $params, array $body, array $user): array {
        $consultations = new ConsultationService();
        $result = $consultations->sendToGeneralDoctor($user, (string) ($body['message'] ?? ''));
        return (new ConversationService())->messages($result['conversation']['id'], 0, 50);
    }, $astronaut + ['rate' => ['ai_message', 20, 60]]);

    // ---------- Other AI analyses ----------

    $router->post('/ai/analyze', function (array $params, array $body, array $user): array {
        return (new AIService())->analyzeHealth((int) $user['id']);
    }, $astronaut + ['rate' => ['ai_analyze', 10, 60]]);

    $router->post('/ai/alerts/{id}/explain', function (array $params, array $body, array $user): array {
        $alert = (new AlertService())->get($user, $params['id']);
        return (new AIService())->explainAlert((int) $user['id'], $alert);
    }, ['roles' => ['astronaut', 'mission_admin'], 'rate' => ['ai_explain', 10, 60]]);
};
