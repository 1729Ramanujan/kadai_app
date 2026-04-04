<?php
require_once __DIR__ . '/../config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function find_active_reset(PDO $pdo, string $token): ?array
{
    if ($token === '') {
        return null;
    }

    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare(
        'SELECT
            pr.id,
            pr.user_id,
            pr.expires_at,
            u.email,
            u.password_hash,
            u.google_email,
            u.google_sub
         FROM password_resets pr
         INNER JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = :token_hash
           AND pr.used_at IS NULL
           AND pr.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([':token_hash' => $tokenHash]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    // Google専用ユーザーには再設定不可
    if (empty($row['password_hash'])) {
        return null;
    }

    return $row;
}

$token = trim((string)($_GET['token'] ?? ''));
$reset = find_active_reset($pdo, $token);
$isValid = $reset !== null;
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>パスワード再設定</title>
    <link rel="stylesheet" href="<?= h(base_url('assets/css/style.css')) ?>">
    <style>
        .authPage {
            min-height: 100vh;
            padding: 24px 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .authCard {
            width: 100%;
            max-width: 560px;
        }

        .authStatus {
            margin: 0 0 14px;
            padding: 12px 14px;
            border-radius: 14px;
            font-size: 14px;
            line-height: 1.7;
            font-weight: 700;
        }

        .authStatus--error {
            color: #9b2c2c;
            background: rgba(200, 92, 92, 0.10);
            border: 1px solid rgba(200, 92, 92, 0.22);
        }

        .authHelp {
            margin-top: -2px;
        }
    </style>
</head>

<body>
    <main class="authPage">
        <section class="card authCard">
            <h1>パスワード再設定</h1>

            <?php if (!$isValid): ?>
                <div class="authStatus authStatus--error">
                    この再設定リンクは無効か、すでに使用済みか、有効期限切れです。
                </div>

                <p class="muted">
                    もう一度プロフィール画面から、パスワード再設定リンクを送信してください。
                </p>

                <div class="buttonRow">
                    <a class="button" href="<?= h(base_url('profile/profile.php')) ?>">プロフィール画面へ戻る</a>
                </div>
            <?php else: ?>
                <p class="muted authHelp">
                    新しいパスワードを入力してください。8文字以上を推奨します。
                </p>

                <form action="reset_password_submit.php" method="post" class="form">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="token" value="<?= h($token) ?>">

                    <label>新しいパスワード
                        <input
                            type="password"
                            name="password"
                            autocomplete="new-password"
                            minlength="8"
                            required>
                    </label>

                    <label>新しいパスワード（確認）
                        <input
                            type="password"
                            name="password_confirm"
                            autocomplete="new-password"
                            minlength="8"
                            required>
                    </label>

                    <div class="buttonRow">
                        <button type="submit">パスワードを更新</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </main>
</body>

</html>