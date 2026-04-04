<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ai/ai_client.php';

require_login();

header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

function json_error(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode([
        'ok' => false,
        'error' => $msg,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function task_chat_due_text(?string $dueAt): string
{
    if (!$dueAt) {
        return '締切なし';
    }

    $ts = strtotime($dueAt);
    if ($ts === false) {
        return '締切なし';
    }

    return date('Y-m-d H:i', $ts);
}

function task_chat_clip(string $text, int $limit): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '') {
        return '';
    }

    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    return mb_substr($text, 0, $limit) . '…';
}

function task_chat_history_to_text(array $messages, int $maxMessages = 4, int $maxCharsPerMessage = 220): string
{
    if ($messages === []) {
        return '（まだ会話履歴はありません）';
    }

    $messages = array_slice($messages, -$maxMessages);
    $lines = [];

    foreach ($messages as $m) {
        $role = (string)($m['role'] ?? '');
        $body = trim((string)($m['body'] ?? ''));

        if ($body === '') {
            continue;
        }

        $body = task_chat_clip($body, $maxCharsPerMessage);

        if ($role === 'assistant') {
            $label = 'AI';
        } elseif ($role === 'system') {
            $label = 'SYSTEM';
        } else {
            $label = 'USER';
        }

        $lines[] = '[' . $label . '] ' . $body;
    }

    return $lines !== [] ? implode("\n\n", $lines) : '（まだ会話履歴はありません）';
}

function task_chat_is_risky_request(string $question): bool
{
    $q = trim($question);
    if ($q === '') {
        return false;
    }

    $patterns = [
        '/\b[1-9][0-9]{2,5}字/u',
        '/レポート.*(書いて|作って|まとめて)/u',
        '/答案.*(書いて|作って)/u',
        '/判決.*(予測して|予想して|書いて)/u',
        '/本文.*(書いて|作って)/u',
        '/結論.*(書いて|出して)/u',
        '/そのまま(使える|提出|貼れる)/u',
        '/コピペ/u',
        '/模範解答/u',
        '/完成答案/u',
        '/提出用/u',
        '/完成版/u',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $q)) {
            return true;
        }
    }

    return false;
}

function task_chat_system_prompt(): string
{
    return <<<SYS
あなたは大学生向けの学習支援AIです。
役割は、学習者の質問に日本語で自然に答え、理解や思考を助けることです。

必ず次を守ってください。
- 質問が事実・制度・概念・背景・判例・用語の説明を求めているなら、その質問に普通に答えてよい
- 質問が考え方や進め方を求めているなら、その内容に応じて方針や確認ポイントを示してよい
- ただし、完成答案、模範解答、提出用の完成文、コピペ可能な長文レポート本文は作らない
- 提出代行に近い依頼には、そのまま応じず、代わりに背景知識・論点整理・確認ポイント・資料の見方など、安全な範囲で役立つ内容を返す
- 有名な事件名、一般に通用する呼び名、文脈上もっとも自然に特定できる対象については、まずその通常の意味で解釈して答えてよい
- 完全には断定できない場合でも、まず最も自然な解釈を一言明示して要点を答え、そのあと必要なら補足で確認する
- 質問に答える前に、安易に確認質問だけを返さない
- ユーザーの質問形式にできるだけ自然に合わせる
- 不要な固定見出しは付けない
- 参考情報を出す場合は、存在が怪しいものを捏造しない
- 冷たく拒否するより、可能な範囲で学習支援になる答えを優先する
SYS;
}

function task_chat_build_user_prompt(array $task, array $recentMessages, string $question, bool $isRisky): string
{
    $title   = trim((string)($task['title'] ?? ''));
    $detail  = trim((string)($task['detail'] ?? ''));
    $status  = trim((string)($task['status'] ?? ''));
    $dueText = task_chat_due_text($task['due_at'] ?? null);
    $history = task_chat_history_to_text($recentMessages, 4, 220);
    $briefDetail = task_chat_clip($detail, 700);

    $riskNote = $isRisky
        ? "【安全上の注意】\nこの質問は提出代行に近づきやすいので、完成文や模範解答は作らず、背景知識・論点整理・確認ポイント・考える材料を中心に返してください。\n"
        : '';

    return <<<TXT
以下は大学の課題と、その課題に関する質問です。
ユーザーの今回の質問に、できるだけ自然に直接答えてください。

【課題タイトル】
{$title}

【締切】
{$dueText}

【状態】
{$status}

【課題詳細（必要範囲のみ）】
{$briefDetail}

【直近の会話】
{$history}

【今回のユーザー質問】
{$question}

{$riskNote}注意:
- 有名な事件名や一般に通用する呼び名は、文脈上もっとも自然な意味でまず解釈してください
- 少し曖昧さが残る場合でも、まず「通常はこの意味だと解して答えます」のように短く前置きして要点を答えてください
- 質問が事実や背景説明を求めているなら、その内容に普通に答えてください
- 質問が方針や進め方を求めているなら、それに応じて答えてください
- 参考文献を1~3件ほど含めてください。ただし、自身のない文献を挙げる場合は、「参考文献なし」としてください。
- 実在しない文献や、文献として信用できないものは決して出力しないでください。
- 完成答案、提出用本文、コピペ可能な長文は作らないでください
- 不要な定型見出しは付けなくて構いません
TXT;
}

function task_chat_safe_fallback(bool $isRisky): string
{
    if ($isRisky) {
        return 'その依頼は提出用の完成文に近いため、そのままの形では作れません。代わりに、背景知識の整理・論点の切り分け・考える順序なら手伝えます。必要なら「どの論点を整理すればいいか」「この制度や判例の意味は何か」のように聞いてください。';
    }

    return 'うまく回答を生成できませんでした。必要なら、知りたい点を少し短くしてもう一度聞いてください。背景説明・制度の整理・考え方の相談であれば対応できます。';
}

function task_chat_count_essay_markers(string $text): int
{
    $markers = [
        '/序論/u',
        '/本論/u',
        '/結論/u',
        '/結語/u',
        '/第1段落/u',
        '/第2段落/u',
        '/第3段落/u',
    ];

    $count = 0;
    foreach ($markers as $pattern) {
        if (preg_match($pattern, $text)) {
            $count++;
        }
    }

    return $count;
}

function task_chat_filter_answer(string $raw, bool $isRisky): array
{
    $text = trim($raw);
    if ($text === '') {
        return [
            'body' => task_chat_safe_fallback($isRisky),
            'safety_flag' => 'blocked',
        ];
    }

    $normalized = preg_replace("/\r\n|\r/u", "\n", $text);
    $normalized = is_string($normalized) ? trim($normalized) : $text;

    $severePatterns = [
        '/模範解答/u',
        '/そのまま提出/u',
        '/完成答案/u',
        '/提出用/u',
        '/コピペ/u',
        '/完成版/u',
        '/そのまま使/u',
        '/そのまま貼/u',
    ];

    foreach ($severePatterns as $pattern) {
        if (preg_match($pattern, $normalized)) {
            return [
                'body' => task_chat_safe_fallback($isRisky),
                'safety_flag' => 'filtered',
            ];
        }
    }

    $length = mb_strlen($normalized);
    $essayMarkers = task_chat_count_essay_markers($normalized);

    if ($isRisky && ($length > 2200 || ($length > 1200 && $essayMarkers >= 2))) {
        return [
            'body' => task_chat_safe_fallback(true),
            'safety_flag' => 'filtered',
        ];
    }

    return [
        'body' => trim($normalized),
        'safety_flag' => 'normal',
    ];
}

function task_chat_thread_title(array $task): string
{
    $base = trim((string)($task['title'] ?? ''));
    return $base !== '' ? $base . ' / AI相談' : 'AI相談';
}

function ai_call_openai_chat_text(
    string $apiKey,
    string $model,
    string $system,
    string $userPrompt
): string {
    $payload = [
        'model' => $model,
        'input' => [
            [
                'role' => 'system',
                'content' => [
                    ['type' => 'input_text', 'text' => $system],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $userPrompt],
                ],
            ],
        ],
        'text' => [
            'format' => ['type' => 'text'],
            'verbosity' => 'medium',
        ],
        'max_output_tokens' => 1000,
    ];

    $data = ai_post_json(
        'https://api.openai.com/v1/responses',
        [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        $payload,
        120
    );

    $answer = ai_extract_openai_output_text($data);
    if ($answer === '') {
        throw new RuntimeException('OpenAI returned empty text output');
    }

    return $answer;
}

function ai_call_gemini_chat_text(
    string $apiKey,
    string $model,
    string $system,
    string $userPrompt
): string {
    $payload = [
        'systemInstruction' => [
            'parts' => [
                ['text' => $system],
            ],
        ],
        'contents' => [
            [
                'parts' => [
                    ['text' => $userPrompt],
                ],
            ],
        ],
        'generationConfig' => [
            'temperature' => 0.3,
            'maxOutputTokens' => 1000,
        ],
    ];

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model)
        . ':generateContent?key='
        . rawurlencode($apiKey);

    $data = ai_post_json(
        $url,
        ['Content-Type: application/json'],
        $payload,
        120
    );

    $answer = ai_extract_gemini_text($data);
    if ($answer === '') {
        throw new RuntimeException('Gemini returned empty text output');
    }

    return $answer;
}

function ai_call_claude_chat_text(
    string $apiKey,
    string $model,
    string $system,
    string $userPrompt
): string {
    $payload = [
        'model' => $model,
        'max_tokens' => 1000,
        'system' => $system,
        'messages' => [
            [
                'role' => 'user',
                'content' => $userPrompt,
            ],
        ],
    ];

    $data = ai_post_json(
        'https://api.anthropic.com/v1/messages',
        [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        $payload,
        120
    );

    $answer = ai_extract_claude_text($data);
    if ($answer === '') {
        throw new RuntimeException('Claude returned empty text output');
    }

    return $answer;
}

function ai_generate_chat_text(
    string $provider,
    string $model,
    string $apiKey,
    string $system,
    string $userPrompt
): string {
    if ($provider === 'openai') {
        return ai_call_openai_chat_text($apiKey, $model, $system, $userPrompt);
    }
    if ($provider === 'gemini') {
        return ai_call_gemini_chat_text($apiKey, $model, $system, $userPrompt);
    }
    if ($provider === 'claude') {
        return ai_call_claude_chat_text($apiKey, $model, $system, $userPrompt);
    }

    throw new RuntimeException('Unsupported provider: ' . $provider);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
    json_error('CSRF invalid', 403);
}

$task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
if (!$task_id) {
    json_error('task_id invalid');
}

$question = trim((string)($_POST['message'] ?? ''));
if ($question === '') {
    json_error('message is empty');
}

if (mb_strlen($question) > 2000) {
    json_error('message is too long');
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

$selection = ai_resolve_selection(
    (string)($_POST['provider'] ?? ''),
    (string)($_POST['model'] ?? '')
);

$provider = $selection['provider'];
$model    = $selection['model'];

$stmt = $pdo->prepare('
    SELECT
        id,
        title,
        detail,
        due_at,
        status
    FROM tasks
    WHERE id = :id AND user_id = :uid
    LIMIT 1
');
$stmt->execute([
    ':id' => $task_id,
    ':uid' => $user_id,
]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    json_error('task not found', 404);
}

$stmtThread = $pdo->prepare('
    SELECT id
    FROM task_chat_threads
    WHERE user_id = :uid
      AND task_id = :tid
      AND is_active = 1
    ORDER BY id DESC
    LIMIT 1
');
$stmtThread->execute([
    ':uid' => $user_id,
    ':tid' => $task_id,
]);
$thread_id = (int)($stmtThread->fetchColumn() ?: 0);

if ($thread_id <= 0) {
    $insThread = $pdo->prepare('
        INSERT INTO task_chat_threads (
            user_id,
            task_id,
            mode,
            title,
            is_active,
            last_message_at,
            created_at,
            updated_at
        ) VALUES (
            :uid,
            :tid,
            :mode,
            :title,
            1,
            NULL,
            NOW(),
            NOW()
        )
    ');
    $insThread->execute([
        ':uid' => $user_id,
        ':tid' => $task_id,
        ':mode' => 'chat',
        ':title' => mb_substr(task_chat_thread_title($task), 0, 255),
    ]);

    $thread_id = (int)$pdo->lastInsertId();
}

$stmtHist = $pdo->prepare('
    SELECT role, body, created_at
    FROM task_chat_messages
    WHERE thread_id = :thread_id
    ORDER BY id DESC
    LIMIT 6
');
$stmtHist->execute([
    ':thread_id' => $thread_id,
]);
$recentMessages = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
$recentMessages = array_reverse($recentMessages);

$apiKey = ai_get_api_key($provider);
if ($apiKey === '') {
    json_error($provider . ' API key is not set on server', 500);
}

$isRisky = task_chat_is_risky_request($question);
$systemPrompt = task_chat_system_prompt();
$userPrompt   = task_chat_build_user_prompt($task, $recentMessages, $question, $isRisky);

try {
    $rawAnswer = ai_generate_chat_text(
        $provider,
        $model,
        $apiKey,
        $systemPrompt,
        $userPrompt
    );
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}

$filtered = task_chat_filter_answer($rawAnswer, $isRisky);
$answer = (string)$filtered['body'];
$safetyFlag = (string)$filtered['safety_flag'];

try {
    $pdo->beginTransaction();

    $insUser = $pdo->prepare('
        INSERT INTO task_chat_messages (
            thread_id,
            user_id,
            role,
            body,
            safety_flag,
            provider,
            model,
            created_at
        ) VALUES (
            :thread_id,
            :user_id,
            :role,
            :body,
            :safety_flag,
            NULL,
            NULL,
            NOW()
        )
    ');
    $insUser->execute([
        ':thread_id' => $thread_id,
        ':user_id' => $user_id,
        ':role' => 'user',
        ':body' => $question,
        ':safety_flag' => 'normal',
    ]);

    $userMessageId = (int)$pdo->lastInsertId();

    $insAi = $pdo->prepare('
        INSERT INTO task_chat_messages (
            thread_id,
            user_id,
            role,
            body,
            safety_flag,
            provider,
            model,
            created_at
        ) VALUES (
            :thread_id,
            :user_id,
            :role,
            :body,
            :safety_flag,
            :provider,
            :model,
            NOW()
        )
    ');
    $insAi->execute([
        ':thread_id' => $thread_id,
        ':user_id' => $user_id,
        ':role' => 'assistant',
        ':body' => $answer,
        ':safety_flag' => $safetyFlag,
        ':provider' => $provider,
        ':model' => $model,
    ]);

    $assistantMessageId = (int)$pdo->lastInsertId();

    $updThread = $pdo->prepare('
        UPDATE task_chat_threads
        SET
            last_message_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
          AND user_id = :uid
        LIMIT 1
    ');
    $updThread->execute([
        ':id' => $thread_id,
        ':uid' => $user_id,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_error('failed to save chat messages', 500);
}

$now = date('Y-m-d H:i:s');

echo json_encode([
    'ok' => true,
    'thread_id' => $thread_id,
    'mode' => 'chat',
    'mode_label' => 'AI相談',
    'provider' => $provider,
    'model' => $model,
    'generated_at' => $now,
    'user_message' => [
        'id' => $userMessageId,
        'role' => 'user',
        'body' => $question,
        'created_at' => $now,
    ],
    'assistant_message' => [
        'id' => $assistantMessageId,
        'role' => 'assistant',
        'body' => $answer,
        'safety_flag' => $safetyFlag,
        'provider' => $provider,
        'model' => $model,
        'created_at' => $now,
    ],
], JSON_UNESCAPED_UNICODE);
