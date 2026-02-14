<?php
// ログイン機能をまとめている共通ファイル。データベースなどの設定とか

// 型宣言を厳密に取り扱うことによって関数などでエラーを発見しやすくし、早めに対処できるようにしている
declare(strict_types=1);
// ログイン機能を作りたいので宣言
session_start();

// サーバー上のMySQLに接続するための情報をまとめてる
$db_host = 'mysql80.sh-sgmt.sakura.ne.jp';
$db_name = 'sh-sgmt_kadai_app';   
$db_user = 'sh-sgmt_kadai_app'; 
$db_pass = 'mju7890-';

// 先ほど与えた情報から接続のための文字列を作成
$dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
$options = [
  // エラーが発生したらその時点ですぐに止めてエラーを出すように設定
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  // DBからデータをとってくるときにそのデータの形を決めて扱いやすくするためのコード
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  // MySQLのデータの形を扱いやすく、安全性の高い形にすることでエラーが出にくいようにしている
  PDO::ATTR_EMULATE_PREPARES => false,
];
// DBに直接接続する部分。今はPDO::ARTTR_ERRMODEの部分があるからこちらも理由がわかるけど、実際はユーザーにエラーの原因がわからないようにtry{}で書くほうがいい
try {
  $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (PDOException $e) {
  // 本番では詳細を画面に出さない、開発中のみ
  error_log('DB Connection Error: ' . $e->getMessage());
  // ユーザー側の画面に表示される文言を設定
  exit('データベースに接続できませんでした。時間をおいて再度お試しください。');
}

// サーバーに送られる情報が本当にユーザーの画面から送られたのかを確認するコード
// csrf（確認コード的なもの）をユーザー側の画面で発行して、それが一致しているかどうかを確認することで認証する
// 悪意あるサーバーがデータを操作する通信をユーザーのブラウザから送らせた場合、判断がつかないが、scrfトークンがあれば大丈夫
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// ユーザーが入力したものをただの文字列として認識して、プログラミング言語と読み取らないようにする関数（クロスサイトスクリプティング攻撃の防止）
function h(string $s): string
{
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ログインが必要なページに入る際に、きちんとログインされているかを確認する
// user_idがセッションにあるかを確認してあれば通す、なければindex.php（ログイン画面）に遷移するように設定
function require_login(): void
{
  if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
  }
}
