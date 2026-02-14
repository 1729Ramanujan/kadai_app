<?php
// 全てのサイトで使う共通の関数を読み込んでる（config.phpの部分）
require_once __DIR__ . '/config.php';

// セッション部分に入っているエラー分を取り出して変数に入れている
$flash_error = $_SESSION['flash_error'] ?? '';
// その後セッションに入っているエラー分を消している（次のページでは表示させないために）
unset($_SESSION['flash_error']);

// ログイン状態の有無を判定して、表示するボタンの設定を変えるための変数。trueかfalseでユーザーのログイン状態を判定している
$is_logged_in = !empty($_SESSION['user_id']);
// ログインしたユーザーのメールアドレスをセッションから取り出している
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
        <!-- is_logged_inがtrueならログイン済み、falseなら未ログインを表示するように設定 -->
        <p class="status" id="status"><?= $is_logged_in ? 'ログイン済み' : '未ログイン' ?></p>
        <!-- もし何かflash_errorに中身が表示されているときにだけ表示する -->
        <?php if ($flash_error): ?>
            <p class="error"><?= h($flash_error) ?></p>
        <?php endif; ?>
    </div>

    <!-- ここからもis_logged_inの状態によって表示するものを分けている -->
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