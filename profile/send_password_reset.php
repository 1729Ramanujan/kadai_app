<?php
require_once __DIR__ . '/../config.php';
require_login();

function redirect_profile(string $query = ''): void
{
    $url = 'profile.php';
    if ($query !== '') {
        $url .= '?' . $query;
    }
    header('Location: ' . $url);
    exit;
}

function absolute_url(string $url): string
{
    if (preg_match('~^https?://~i', $url)) {
        return $url;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');

    if ($host === '') {
        return $url;
    }

    return $scheme . '://' . $host . '/' . ltrim($url, '/');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_profile('reset_error=invalid_request');
}

if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    redirect_profile('reset_error=invalid_request');
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    redirect_profile('reset_error=invalid_request');
}

$stmt = $pdo->prepare(
    'SELECT id, email, password_hash, google_email, google_sub
     FROM users
     WHERE id = :id
     LIMIT 1'
);
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch();

if (!$user) {
    redirect_profile('reset_error=invalid_request');
}

/*
|--------------------------------------------------------------------------
| password_hash がない = Googleログイン専用ユーザー
|--------------------------------------------------------------------------
*/
if (empty($user['password_hash'])) {
    redirect_profile('reset_error=google_only');
}

$email = trim((string)($user['email'] ?? ''));
if ($email === '') {
    redirect_profile('reset_error=invalid_request');
}

try {
    $token = bin2hex(random_bytes(32));
} catch (Throwable $e) {
    redirect_profile('reset_error=send_failed');
}

$token_hash = hash('sha256', $token);
$expires_at = date('Y-m-d H:i:s', time() + 3600); // 1時間有効

try {
    $pdo->beginTransaction();

    // 以前の未使用トークンは削除
    $deleteStmt = $pdo->prepare('DELETE FROM password_resets WHERE user_id = :user_id');
    $deleteStmt->execute([':user_id' => $user_id]);

    $insertStmt = $pdo->prepare(
        'INSERT INTO password_resets (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, :expires_at)'
    );
    $insertStmt->execute([
        ':user_id'    => $user_id,
        ':token_hash' => $token_hash,
        ':expires_at' => $expires_at,
    ]);

    $reset_id = (int)$pdo->lastInsertId();

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    redirect_profile('reset_error=send_failed');
}

/*
|--------------------------------------------------------------------------
| 再設定URL
| 後で auth/reset_password.php を作る前提
|--------------------------------------------------------------------------
*/
$resetBase = base_url('auth/reset_password.php');
$resetUrl = absolute_url($resetBase) . '?token=' . urlencode($token);

/*
|--------------------------------------------------------------------------
| メール送信設定
| MAIL_FROM / MAIL_FROM_NAME が config.php にあればそれを優先
|--------------------------------------------------------------------------
*/
$appName = defined('APP_NAME') ? APP_NAME : 'kadAI';

$host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'example.com'));
$defaultFrom = 'no-reply@' . preg_replace('/^www\./i', '', $host);

$mailFrom = defined('MAIL_FROM') ? MAIL_FROM : $defaultFrom;
$mailFromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : $appName;

if (function_exists('mb_language')) {
    mb_language('Japanese');
}
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

$subject = '【' . $appName . '】パスワード再設定のご案内';

$body = <<<EOT
{$appName} のパスワード再設定がリクエストされました。

以下のリンクから、新しいパスワードを設定してください。
{$resetUrl}

このリンクの有効期限は1時間です。

このメールに心当たりがない場合は、操作せずそのまま破棄してください。
EOT;

$headers = [];
$headers[] = 'From: ' . mb_encode_mimeheader($mailFromName) . ' <' . $mailFrom . '>';
$headers[] = 'Reply-To: ' . $mailFrom;
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'Content-Transfer-Encoding: 8bit';

$sent = mb_send_mail(
    $email,
    $subject,
    $body,
    implode("\r\n", $headers),
    '-f ' . $mailFrom
);

if (function_exists('mb_send_mail')) {
    $sent = @mb_send_mail($email, $subject, $body, implode("\r\n", $headers));
}

if (!$sent) {
    // 送信失敗時は今回作ったトークンを削除しておく
    try {
        $cleanupStmt = $pdo->prepare('DELETE FROM password_resets WHERE id = :id');
        $cleanupStmt->execute([':id' => $reset_id]);
    } catch (Throwable $e) {
        // cleanup失敗は握りつぶす
    }

    redirect_profile('reset_error=send_failed');
}

redirect_profile('reset_sent=1');
