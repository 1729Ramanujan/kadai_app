<?php
require_once __DIR__ . '/config.php';

$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

$is_logged_in = !empty($_SESSION['user_id']);
$email = $_SESSION['user_email'] ?? '';
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>課題処理アプリ ログイン画面</title>
    <link rel="stylesheet" href="css/style.css">
</head>

<body>
    <h1>課題処理アプリ</h1>

    <div class="card">
        <p class="status" id="status"><?= $is_logged_in ? 'ログイン済み' : '未ログイン' ?></p>
        <?php if ($flash_error): ?>
            <p class="error"><?= h($flash_error) ?></p>
        <?php endif; ?>
    </div>

    <?php if (!$is_logged_in): ?>
        <div class="card" id="signedOutView">
            <h2>ログイン</h2>
            <form method="post" action="login.php">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <label>メールアドレス
                    <input type="email" name="email" autocomplete="email" required>
                </label>
                <label>パスワード
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <button type="submit">ログイン</button>
            </form>

            <div class="divider"></div>

            <h2>新規登録</h2>
            <form method="post" action="signup.php">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <label>メールアドレス
                    <input type="email" name="email" autocomplete="email" required>
                </label>
                <label>パスワード（6文字以上）
                    <input type="password" name="password" autocomplete="new-password" minlength="6" required>
                </label>
                <button type="submit">登録</button>
            </form>
        </div>
    <?php else: ?>
        <div class="secondary" id="signedInView">
            <h2>ログイン中</h2>
            <p>ユーザー：<?= h($email ?: '(emailなし)') ?></p>
            <div class="buttonRow">
                <a class="button danger" href="logout.php">ログアウト</a>
                <a class="button" href="timetable.php">時間割へ</a>
            </div>
        </div>
    <?php endif; ?>
</body>

</html>