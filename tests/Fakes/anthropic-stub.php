<?php

// Lokaler Stub der Anthropic-API für Tests (php -S … anthropic-stub.php): Modell-Abfrage und -Liste;
// Schlüssel „bad“ wird abgelehnt.
declare(strict_types=1);

header('Content-Type: application/json');
$headers = array_change_key_case(getallheaders());
$model = static fn (string $id): array => ['type' => 'model', 'id' => $id, 'display_name' => 'Claude Stub '.$id, 'created_at' => '2026-01-01T00:00:00Z'];

// Das SDK sendet den Schlüssel als „Authorization: Bearer …“ (ältere Clients als x-api-key).
$key = $headers['x-api-key'] ?? preg_replace('/^Bearer\s+/i', '', (string) ($headers['authorization'] ?? ''));

if ($key === 'bad') {
    http_response_code(401);
    echo json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']]);

    return;
}

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/v1/models') {
    echo json_encode(['data' => [$model('claude-opus-5-5'), $model('claude-sonnet-5-5')], 'has_more' => false, 'first_id' => 'claude-opus-5-5', 'last_id' => 'claude-sonnet-5-5']);
} elseif (str_starts_with($path, '/v1/models/') && in_array($id = substr($path, 11), ['claude-opus-5-5', 'claude-sonnet-5-5'], true)) {
    echo json_encode($model($id));
} else {
    http_response_code(404);
    echo json_encode(['type' => 'error', 'error' => ['type' => 'not_found_error', 'message' => 'not found']]);
}
