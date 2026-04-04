<?php
require_once __DIR__ . '/../config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function password_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    return strlen($value);
}

function find_active_reset_for_update(PDO $pdo, string $token): ?array
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
         LIMIT 1
         FOR UPDATE'
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

$success = false;
$error = '';
$showForm = false;

$token = trim((string)($_POST['token'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$passwordConfirm = (string)($_POST['password_confirm'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $error = '無効なアクセスです。';
} else {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $error = 'セッションが無効です。もう一度メール内のリンクからやり直してください。';
    }

    if ($token === '') {
        $error = '再設定トークンが見つかりません。';
    }

    if ($error === '') {
        try {
            $pdo->beginTransaction();

            $reset = find_active_reset_for_update($pdo, $token);

            if (!$reset) {
                $pdo->rollBack();
                $error = 'この再設定リンクは無効か、すでに使用済みか、有効期限切れです。';
            } else {
                $showForm = true;

                if (password_length($password) < 8) {
                    $pdo->rollBack();
                    $error = 'パスワードは8文字以上で入力してください。';
                } elseif ($password !== $passwordConfirm) {
                    $pdo->rollBack();
                    $error = '確認用パスワードが一致していません。';
                } else {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);

                    $updateUserStmt = $pdo->prepare(
                        'UPDATE users
                         SET password_hash = :password_hash
                         WHERE id = :user_id
                         LIMIT 1'
                    );
                    $updateUserStmt->execute([
                        ':password_hash' => $newHash,
                        ':user_id' => (int)$reset['user_id'],
                    ]);

                    $updateResetStmt = $pdo->prepare(
                        'UPDATE password_resets
                         SET used_at = NOW()
                         WHERE id = :id
                         LIMIT 1'
                    );
                    $updateResetStmt->execute([
                        ':id' => (int)$reset['id'],
                    ]);

                    // 同ユーザーの他のトークンも片付ける
                    $cleanupStmt = $pdo->prepare(
                        'DELETE FROM password_resets
                         WHERE user_id = :user_id
                           AND id <> :id'
                    );
                    $cleanupStmt->execute([
                        ':user_id' => (int)$reset['user_id'],
                        ':id' => (int)$reset['id'],
                    ]);

                    $pdo->commit();
                    $success = true;
                    $showForm = false;
                    $password = '';
                    $passwordConfirm = '';
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'パスワード更新に失敗しました。時間をおいて再度お試しください。';
            $showForm = false;
        }
    }
}

// エラー時に、トークンがまだ有効なら再入力フォームを出す
if (!$success && $error !== '' && $showForm === false && $token !== '') {
    try {
        $checkStmt = $pdo->prepare(
            'SELECT pr.id
             FROM password_resets pr
             INNER JOIN users u ON u.id = pr.user_id
             WHERE pr.token_hash = :token_hash
               AND pr.used_at IS NULL
               AND pr.expires_at > NOW()
               AND u.password_hash IS NOT NULL
             LIMIT 1'
        );
        $checkStmt->execute([':token_hash' => hash('sha256', $token)]);
        $showForm = (bool)$checkStmt->fetch();
    } catch (Throwable $e) {
        $showForm = false;
    }
}
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

        .authStatus--success {
            color: #1d6b43;
            background: rgba(88, 184, 119, 0.12);
            border: 1px solid rgba(88, 184, 119, 0.24);
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

            <?php if ($success): ?>
                <div class="authStatus authStatus--success">
                    パスワードを更新しました。新しいパスワードでログインできます。
                </div>

                <div class="buttonRow">
                    <a class="button" href="<?= h(base_url('auth/login_view.php')) ?>">ログイン画面へ戻る</a>
                </div>

            <?php else: ?>
                <?php if ($error !== ''): ?>
                    <div class="authStatus authStatus--error">
                        <?= h($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($showForm): ?>
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
                <?php else: ?>
                    <p class="muted">
                        もう一度プロフィール画面から、パスワード再設定リンクを送信してください。
                    </p>

                    <div class="buttonRow">
                        <a class="button" href="<?= h(base_url('profile/profile.php')) ?>">プロフィール画面に戻る</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
</body>

</html>