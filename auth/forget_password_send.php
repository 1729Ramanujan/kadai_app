<?php
require_once __DIR__ . '/../config.php';

function redirect_forget_view(string $type, string $message): void
{
    $_SESSION[$type] = $message;
    header('Location: forget_password_view.php');
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
    redirect_forget_view('flash_error', '無効なアクセスです。');
}

if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    redirect_forget_view('flash_error', 'セッションが無効です。もう一度お試しください。');
}

$email = trim((string)($_POST['email'] ?? ''));
if ($email === '') {
    redirect_forget_view('flash_error', 'メールアドレスを入力してください。');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_forget_view('flash_error', 'メールアドレスの形式が正しくありません。');
}

/*
|--------------------------------------------------------------------------
| ここではアカウント存在有無を画面に出さない
|--------------------------------------------------------------------------
*/
$genericMessage = '該当するアカウントが存在する場合、パスワード再設定リンクを送信しました。';

$stmt = $pdo->prepare(
    'SELECT id, email, password_hash, google_email, google_sub
     FROM users
     WHERE email = :email
     LIMIT 1'
);
$stmt->execute([':email' => $email]);
$user = $stmt->fetch();

/*
|--------------------------------------------------------------------------
| 見つからない or Googleログイン専用なら、成功っぽく返す
|--------------------------------------------------------------------------
*/
if (!$user || empty($user['password_hash'])) {
    redirect_forget_view('flash_message', $genericMessage);
}

try {
    $token = bin2hex(random_bytes(32));
} catch (Throwable $e) {
    redirect_forget_view('flash_error', '再設定トークンの生成に失敗しました。時間をおいて再度お試しください。');
}

$tokenHash = hash('sha256', $token);
$expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1時間

try {
    $pdo->beginTransaction();

    $deleteStmt = $pdo->prepare('DELETE FROM password_resets WHERE user_id = :user_id');
    $deleteStmt->execute([
        ':user_id' => (int)$user['id'],
    ]);

    $insertStmt = $pdo->prepare(
        'INSERT INTO password_resets (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, :expires_at)'
    );
    $insertStmt->execute([
        ':user_id'    => (int)$user['id'],
        ':token_hash' => $tokenHash,
        ':expires_at' => $expiresAt,
    ]);

    $resetId = (int)$pdo->lastInsertId();

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    redirect_forget_view('flash_error', '再設定リンクの発行に失敗しました。時間をおいて再度お試しください。');
}

$resetBase = base_url('auth/reset_password.php');
$resetUrl = absolute_url($resetBase) . '?token=' . urlencode($token);

$appName = defined('APP_NAME') ? APP_NAME : 'kadAI';
$mailFrom = defined('MAIL_FROM') ? MAIL_FROM : '';
$mailFromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : $appName;

if ($mailFrom === '') {
    try {
        $cleanupStmt = $pdo->prepare('DELETE FROM password_resets WHERE id = :id');
        $cleanupStmt->execute([':id' => $resetId]);
    } catch (Throwable $e) {
    }
    redirect_forget_view('flash_error', '送信元メール設定が未設定です。config.php を確認してください。');
}

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

$sent = false;

if (function_exists('mb_send_mail')) {
    $sent = mb_send_mail(
        (string)$user['email'],
        $subject,
        $body,
        implode("\r\n", $headers),
        '-f ' . $mailFrom
    );
}

if (!$sent) {
    try {
        $cleanupStmt = $pdo->prepare('DELETE FROM password_resets WHERE id = :id');
        $cleanupStmt->execute([':id' => $resetId]);
    } catch (Throwable $e) {
    }

    redirect_forget_view('flash_error', '再設定メールの送信に失敗しました。時間をおいて再度お試しください。');
}

redirect_forget_view('flash_message', $genericMessage);
