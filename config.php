<?php

declare(strict_types=1);
session_start();

$db_host = 'mysql80.sh-sgmt.sakura.ne.jp';
$db_name = 'sh-sgmt_kadai_app';   
$db_user = 'sh-sgmt_kadai_app'; 
$db_pass = '';

$dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES => false,
];
$pdo = new PDO($dsn, $db_user, $db_pass, $options);

// CSRF（フォーム用）
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function h(string $s): string
{
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function require_login(): void
{
  if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
  }
}
