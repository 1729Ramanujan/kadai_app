<?php
require_once __DIR__ . '/../config.php';

$flash_message = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . base_url('timetable/timetable.php'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>パスワード再設定 | kadAI</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .authSubNote {
            margin: 0 0 18px;
        }

        .authLinksSingle {
            margin-top: 18px;
            text-align: center;
            font-size: 14px;
        }

        .authLinksSingle a {
            font-weight: 700;
            text-decoration: none;
        }

        .authLinksSingle a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <div class="authWrap">
        <div class="authCard">
            <h1 class="authTitle">パスワード再設定</h1>
            <p class="authLead">
                登録済みのメールアドレスを入力してください。<br>
                パスワード再設定用のリンクを送信します。
            </p>

            <?php if ($flash_message): ?>
                <p class="success"><?= h($flash_message) ?></p>
            <?php endif; ?>

            <?php if ($flash_error): ?>
                <p class="error"><?= h($flash_error) ?></p>
            <?php endif; ?>

            <p class="muted authSubNote">
                Googleログイン専用アカウントには、再設定メールは送信されません。
            </p>

            <form method="post" action="forget_password_send.php">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">

                <label>
                    メールアドレス
                    <input type="email" name="email" autocomplete="email" required>
                </label>

                <button type="submit">再設定リンクを送る</button>
            </form>

            <div class="divider"></div>

            <p class="authLinksSingle">
                <a href="login_view.php">ログイン画面へ戻る</a>
            </p>
        </div>
    </div>
</body>

</html>