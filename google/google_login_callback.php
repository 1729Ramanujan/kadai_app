<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$secrets = '/home/sh-sgmt/google_secrets.php';
if (is_file($secrets)) {
    require_once $secrets;
}

$code  = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');

if ($code === '' || $state === '') {
    http_response_code(400);
    exit('Missing code/state');
}
if (!hash_equals($_SESSION['google_login_state'] ?? '', $state)) {
    http_response_code(403);
    exit('Invalid state');
}

$return = (string)($_SESSION['google_login_return'] ?? '../timetable/timetable.php');
unset($_SESSION['google_login_state'], $_SESSION['google_login_return']);

$client = new Google\Client();
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);

// 新しい配置に合わせる
$client->setRedirectUri('https://sh-sgmt.sakura.ne.jp/php/kadai_app/google/google_login_callback.php');
$client->setScopes(['openid', 'email', 'profile']);

$token = $client->fetchAccessTokenWithAuthCode($code);
if (isset($token['error'])) {
    http_response_code(500);
    exit('OAuth error: ' . ($token['error_description'] ?? $token['error']));
}

$idToken = (string)($token['id_token'] ?? '');
$payload = $client->verifyIdToken($idToken);
if (!$payload || !is_array($payload)) {
    http_response_code(500);
    exit('Invalid id_token');
}

$sub   = (string)($payload['sub'] ?? '');
$email = (string)($payload['email'] ?? '');

if ($sub === '' || $email === '') {
    http_response_code(500);
    exit('Missing sub/email');
}

// 1) google_subで探す
$stmt = $pdo->prepare('SELECT id, email, password_hash, google_sub FROM users WHERE google_sub=:sub LIMIT 1');
$stmt->execute([':sub' => $sub]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    $user_id = (int)$user['id'];

    // emailは変更され得るので同期
    $pdo->prepare('UPDATE users SET email=:email, google_email=:g WHERE id=:id LIMIT 1')
        ->execute([
            ':email' => $email,
            ':g'     => $email,
            ':id'    => $user_id
        ]);
} else {
    // 2) emailで探す
    $stmt = $pdo->prepare('SELECT id, google_sub, password_hash FROM users WHERE email=:email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $u2 = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($u2) {
        $user_id = (int)$u2['id'];

        if (!empty($u2['google_sub']) && !hash_equals((string)$u2['google_sub'], $sub)) {
            http_response_code(409);
            exit('This email is already linked to another Google account.');
        }

        $pdo->prepare('UPDATE users SET google_sub=:sub, google_email=:g WHERE id=:id LIMIT 1')
            ->execute([
                ':sub' => $sub,
                ':g'   => $email,
                ':id'  => $user_id
            ]);
    } else {
        // 3) 新規作成
        $ins = $pdo->prepare('INSERT INTO users (email, password_hash, google_sub, google_email) VALUES (:email, NULL, :sub, :g)');
        $ins->execute([
            ':email' => $email,
            ':sub'   => $sub,
            ':g'     => $email
        ]);
        $user_id = (int)$pdo->lastInsertId();
    }
}

// アプリ側ログイン
session_regenerate_id(true);
$_SESSION['user_id']    = $user_id;
$_SESSION['user_email'] = $email;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// return安全化
if (!preg_match('#^https?://#', $return) && !str_starts_with($return, '/') && !str_starts_with($return, '../')) {
    $return = '../' . ltrim($return, './');
}

header('Location: ' . $return);
exit;