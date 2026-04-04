<?php

declare(strict_types=1);
session_start();

define('APP_NAME', 'kadAI');
define('MAIL_FROM', '25sugimoto@gmail.com');
define('MAIL_FROM_NAME', 'kadAI');

/* =========================
   環境判定
========================= */
$hostName = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = (
    $hostName === 'localhost' ||
    $hostName === '127.0.0.1' ||
    str_starts_with($hostName, 'localhost:') ||
    str_starts_with($hostName, '127.0.0.1:')
);



$dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (PDOException $e) {
    error_log('DB Connection Error: ' . $e->getMessage());

    if ($isLocal) {
        exit('DB接続エラー: ' . $e->getMessage());
    }

    exit('データベースに接続できませんでした。時間をおいて再度お試しください。');
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function base_url(string $path = ''): string
{
    static $base = null;

    if ($base === null) {
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $appDir = '/' . basename(__DIR__);
        $pos = strpos($scriptName, $appDir);

        $base = ($pos !== false)
            ? substr($scriptName, 0, $pos + strlen($appDir))
            : '';
    }

    return $base . '/' . ltrim($path, '/');
}

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . base_url('index.html'));
        exit;
    }
}

// =========================
// AI 共通設定
// =========================

const AI_PROVIDER_LABELS = [
    'openai' => 'OpenAI',
    'gemini' => 'Gemini',
    'claude' => 'Claude',
];

const AI_ALLOWED_MODELS = [
    'openai' => [
        'gpt-5.4-nano',
        'gpt-5.4-mini',
        'gpt-5.4',
    ],
    'gemini' => [
        'gemini-2.5-flash',
        'gemini-2.5-flash-lite',
        'gemini-2.5-pro',
    ],
    'claude' => [
        'claude-haiku-4-5-20251001',
        'claude-opus-4-6',
        'claude-sonnet-4-6',
    ],
];

const AI_DEFAULT_MODELS = [
    'openai' => 'gpt-5.4-nano',
    'gemini' => 'gemini-2.5-flash',
    'claude' => 'claude-haiku-4-5',
];

const AI_DEFAULT_PROVIDER = 'openai';

function ai_load_secrets(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $loaded = true;

    $hostName = $_SERVER['HTTP_HOST'] ?? '';
    $isLocal = (
        $hostName === 'localhost' ||
        $hostName === '127.0.0.1' ||
        str_starts_with($hostName, 'localhost:') ||
        str_starts_with($hostName, '127.0.0.1:')
    );

    if ($isLocal) {
        $secretsPath = __DIR__ . '/ai_secrets.local.php';
    } else {
        $secretsPath = '/home/sh-sgmt/ai_secrets.php';
    }

    if (is_file($secretsPath)) {
        require_once $secretsPath;
    }
}

function ai_provider_labels(): array
{
    return AI_PROVIDER_LABELS;
}

function ai_allowed_models(): array
{
    return AI_ALLOWED_MODELS;
}

function ai_default_provider(): string
{
    return AI_DEFAULT_PROVIDER;
}

function ai_default_model(string $provider): string
{
    return AI_DEFAULT_MODELS[$provider] ?? AI_DEFAULT_MODELS[AI_DEFAULT_PROVIDER];
}

function ai_is_valid_provider(string $provider): bool
{
    return isset(AI_ALLOWED_MODELS[$provider]);
}

function ai_is_valid_model(string $provider, string $model): bool
{
    if (!ai_is_valid_provider($provider)) {
        return false;
    }

    return in_array($model, AI_ALLOWED_MODELS[$provider], true);
}

function ai_resolve_selection(?string $provider, ?string $model): array
{
    $provider = trim((string)$provider);
    $model    = trim((string)$model);

    if ($provider === '' || !ai_is_valid_provider($provider)) {
        $provider = ai_default_provider();
    }

    if ($model === '' || !ai_is_valid_model($provider, $model)) {
        $model = ai_default_model($provider);
    }

    return [
        'provider' => $provider,
        'model'    => $model,
    ];
}

function ai_get_api_key(string $provider): string
{
    ai_load_secrets();

    return match ($provider) {
        'openai' => defined('OPENAI_API_KEY')
            ? (string)OPENAI_API_KEY
            : (string)(getenv('OPENAI_API_KEY') ?: ''),

        'gemini' => defined('GEMINI_API_KEY')
            ? (string)GEMINI_API_KEY
            : (string)(getenv('GEMINI_API_KEY') ?: ''),

        'claude' => defined('ANTHROPIC_API_KEY')
            ? (string)ANTHROPIC_API_KEY
            : (string)(getenv('ANTHROPIC_API_KEY') ?: ''),

        default => '',
    };
}

function ai_public_config(): array
{
    $provider = ai_default_provider();

    return [
        'providerLabels' => ai_provider_labels(),
        'allowedModels'  => ai_allowed_models(),
        'defaults'       => [
            'provider' => $provider,
            'model'    => ai_default_model($provider),
        ],
    ];
}
