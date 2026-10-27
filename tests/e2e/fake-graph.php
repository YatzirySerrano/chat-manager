<?php

/*
| SIMULACIÓN — a stand-in for the Graph API endpoints the WhatsApp delivery
| job calls, for tests/e2e/subidas.mjs. Nothing reaches Meta: the scenario
| points META_GRAPH_BASE_URL at this router (php -S) and the real
| MetaCloudWhatsAppTransport uploads the stored file to it, streamed, as
| it would to graph.facebook.com.
|
|   POST /{version}/{phone-number-id}/media     -> {"id": "e2e.media.N"}
|   POST /{version}/{phone-number-id}/messages  -> {"messages": [{"id": "wamid.e2e.N"}]}
|
| Each call is appended to FAKE_GRAPH_LOG (JSON lines): the received file's
| size, SHA-256, type and name, or the message payload.
*/

$log = getenv('FAKE_GRAPH_LOG') ?: sys_get_temp_dir().'/fake-graph.jsonl';
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$record = function (array $entry) use ($log): void {
    file_put_contents($log, json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
};

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && str_ends_with($path, '/media')) {
    $file = $_FILES['file'] ?? null;
    $received = is_array($file) && $file['error'] === UPLOAD_ERR_OK;
    $id = 'e2e.media.'.bin2hex(random_bytes(6));
    $record([
        'call' => 'media',
        'id' => $id,
        'error' => is_array($file) ? $file['error'] : null,
        'size' => $received ? filesize($file['tmp_name']) : null,
        'sha256' => $received ? hash_file('sha256', $file['tmp_name']) : null,
        'type' => $_POST['type'] ?? null,
        'name' => is_array($file) ? $file['name'] : null,
        'authorized' => str_starts_with((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), 'Bearer '),
    ]);

    if (! $received) {
        http_response_code(400);
        echo json_encode(['error' => ['message' => 'No file received', 'type' => 'OAuthException', 'code' => 100]]);

        return;
    }

    echo json_encode(['id' => $id]);

    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && str_ends_with($path, '/messages')) {
    $payload = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $type = (string) ($payload['type'] ?? '');
    $id = 'wamid.e2e.'.bin2hex(random_bytes(8));
    $record(['call' => 'messages', 'id' => $id, 'type' => $type, 'media_id' => $payload[$type]['id'] ?? null, 'caption' => $payload[$type]['caption'] ?? null]);
    echo json_encode(['messaging_product' => 'whatsapp', 'contacts' => [['input' => $payload['to'] ?? '', 'wa_id' => $payload['to'] ?? '']], 'messages' => [['id' => $id]]]);

    return;
}

http_response_code(404);
echo json_encode(['error' => ['message' => 'Unknown fake Graph endpoint', 'code' => 100]]);
