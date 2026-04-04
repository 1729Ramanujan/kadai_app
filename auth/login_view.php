<?php
require_once __DIR__ . '/../config.php';

$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if (!empty($_SESSION['user_id'])) {
    header('Location: ../timetable/timetable.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ログイン | 大学生向け時間割アプリ</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>

<body>
    <div class="authWrap">
        <div class="authCard">

            <h1 class="authTitle">ログイン</h1>
            <p class="authLead">登録済みのメールアドレス、またはGoogleアカウントでログインしてください。</p>

            <?php if ($flash_error): ?>
                <p class="error"><?= h($flash_error) ?></p>
            <?php endif; ?>

            <form method="post" action="login.php">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">

                <label>
                    メールアドレス
                    <input type="email" name="email" autocomplete="email" required>
                </label>

                <label>
                    パスワード
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>

                <p class="authSubLink">
                    <a href="forget_password_view.php">パスワードをお忘れですか？</a>
                </p>

                <button type="submit">ログイン</button>
            </form>

            <div class="divider"></div>

            <div class="buttonRow" style="margin-top:12px;">
                <a class="button googleBtn" href="../google/google_login_start.php?return=../timetable/timetable.php">
                    <span class="googleIcon" aria-hidden="true">
                        <svg viewBox="0 0 48 48" width="20" height="20">
                            <path fill="#EA4335" d="M24 9.5c3.54 0 6.73 1.22 9.24 3.6l6.9-6.9C35.98 2.56 30.38 0 24 0 14.64 0 6.56 5.38 2.62 13.22l8.04 6.24C12.58 13.58 17.84 9.5 24 9.5z" />
                            <path fill="#4285F4" d="M46.5 24.5c0-1.64-.14-3.22-.4-4.75H24v9h12.66c-.54 2.9-2.18 5.36-4.66 7.02l7.18 5.58C43.6 37.26 46.5 31.4 46.5 24.5z" />
                            <path fill="#FBBC05" d="M10.66 28.54A14.5 14.5 0 0 1 9.5 24c0-1.58.28-3.1.78-4.54l-8.04-6.24A24 24 0 0 0 0 24c0 3.87.92 7.53 2.54 10.78l8.12-6.24z" />
                            <path fill="#34A853" d="M24 48c6.48 0 11.92-2.14 15.9-5.82l-7.18-5.58c-2 1.34-4.56 2.15-8.72 2.15-6.16 0-11.42-4.08-13.34-9.96l-8.12 6.24C6.52 42.72 14.6 48 24 48z" />
                        </svg>
                    </span>
                    <span>Googleでログイン</span>
                </a>
            </div>

            <div class="divider"></div>

            <p class="authLinks">
                アカウントをお持ちでない方は
                <a href="signup_view.php">新規登録</a>
            </p>
        </div>
    </div>
</body>

</html>