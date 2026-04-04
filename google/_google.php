<?php
// GoogleのAPIを叩くための共通処理
require_once __DIR__ . '/../config.php';
require_login();

require_once __DIR__ . '/../vendor/autoload.php';

$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host) === 1;

$rootDir = dirname(__DIR__);

$secrets = $isLocal
    ? $rootDir . '/google_secrets_local.php'
    : '/home/sh-sgmt/google_secrets.php';

if (!is_file($secrets)) {
    throw new RuntimeException('Google secrets file が見つかりません: ' . $secrets);
}
require_once $secrets;

function google_client(PDO $pdo, int $user_id, bool $needToken = true): Google\Client
{
    $client = new Google\Client();
    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $isLocal = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host) === 1;

    if ($isLocal) {
        $client->setRedirectUri('http://localhost/assignment/kadai_app/google/google_callback.php');
    } else {
        $client->setRedirectUri('https://sh-sgmt.sakura.ne.jp/php/kadai_app/google/google_callback.php');
    }

    // offline + consent は refresh_token を得るために重要
    $client->setAccessType('offline');
    $client->setPrompt('consent');

    $client->setScopes([
        'openid',
        'email',
        'profile',
        Google\Service\Docs::DOCUMENTS,
        Google\Service\Drive::DRIVE_FILE,
    ]);

    if (!$needToken) {
        return $client;
    }

    // users.google_token_json から復元
    $st = $pdo->prepare('SELECT google_token_json FROM users WHERE id=:id LIMIT 1');
    $st->execute([':id' => $user_id]);
    $tokenJson = $st->fetchColumn();

    if (!$tokenJson) {
        throw new RuntimeException('Google連携が必要です');
    }

    $token = json_decode((string)$tokenJson, true);
    if (!is_array($token)) {
        throw new RuntimeException('保存されているGoogleトークンが不正です');
    }

    $client->setAccessToken($token);

    // 期限切れならrefresh
    if ($client->isAccessTokenExpired()) {
        $refresh = $token['refresh_token'] ?? null;
        if (!$refresh) {
            throw new RuntimeException('Google連携をやり直してください（refresh_tokenなし）');
        }

        $client->fetchAccessTokenWithRefreshToken($refresh);
        $newToken = $client->getAccessToken();

        // refresh_token は返ってこないことがあるので保持
        if (!isset($newToken['refresh_token'])) {
            $newToken['refresh_token'] = $refresh;
        }

        $up = $pdo->prepare('UPDATE users SET google_token_json=:t WHERE id=:id LIMIT 1');
        $up->execute([
            ':t'  => json_encode($newToken, JSON_UNESCAPED_UNICODE),
            ':id' => $user_id
        ]);
    }

    return $client;
}

function doc_url(string $docId): string
{
    return 'https://docs.google.com/document/d/' . $docId . '/edit';
}
