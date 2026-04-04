<?php
// Google連携後に code を token に変換してDB保存
require_once __DIR__ . '/_google.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);

$code     = (string)($_GET['code'] ?? '');
$stateB64 = (string)($_GET['state'] ?? '');

if ($code === '' || $stateB64 === '') {
    http_response_code(400);
    exit('Missing code/state');
}

$stateJson = base64_decode($stateB64, true);
$state = json_decode($stateJson ?: '', true);

if (!is_array($state) || !hash_equals($_SESSION['csrf'] ?? '', (string)($state['csrf'] ?? ''))) {
    http_response_code(403);
    exit('Invalid state');
}

$client = google_client($pdo, $user_id, false);
$token  = $client->fetchAccessTokenWithAuthCode($code);

if (isset($token['error'])) {
    http_response_code(500);
    exit('OAuth error: ' . ($token['error_description'] ?? $token['error']));
}

// token保存
$up = $pdo->prepare('UPDATE users SET google_token_json=:t WHERE id=:id LIMIT 1');

// refresh_token が返ってこない回があるので旧トークンから引き継ぐ
$stOld = $pdo->prepare('SELECT google_token_json FROM users WHERE id=:id LIMIT 1');
$stOld->execute([':id' => $user_id]);
$oldJson = (string)$stOld->fetchColumn();
$oldTok  = $oldJson ? json_decode($oldJson, true) : [];

if (empty($token['refresh_token']) && !empty($oldTok['refresh_token'])) {
    $token['refresh_token'] = $oldTok['refresh_token'];
}

$up->execute([
    ':t'  => json_encode($token, JSON_UNESCAPED_UNICODE),
    ':id' => $user_id
]);

// id_token から email を users.google_email に保存
$googleEmail = null;
if (!empty($token['id_token'])) {
    $payload = $client->verifyIdToken((string)$token['id_token']);
    if (is_array($payload) && !empty($payload['email'])) {
        $googleEmail = (string)$payload['email'];
    }
}

if ($googleEmail) {
    $up2 = $pdo->prepare('UPDATE users SET google_email=:mail WHERE id=:id LIMIT 1');
    $up2->execute([
        ':mail' => $googleEmail,
        ':id'   => $user_id
    ]);
}

$return = (string)($state['return'] ?? '../tasks/task.php');

// 外部URLは拒否
if (preg_match('#^https?://#', $return)) {
    $return = '../tasks/task.php';
}

// 相対パス補正
if (!str_starts_with($return, '/') && !str_starts_with($return, '../')) {
    $return = '../' . ltrim($return, './');
}

header('Location: ' . $return);
exit;