<?php

declare(strict_types=1);

require_once __DIR__ . '/ai_prompts.php';

/**
 * 共通 POST JSON
 */
function ai_post_json(string $url, array $headers, array $payload, int $timeout = 120): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('curl error: ' . $err);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('API response JSON parse failed: ' . json_last_error_msg());
    }

    if ($code >= 400) {
        $msg =
            $data['error']['message']
            ?? $data['error']['status']
            ?? $data['message']
            ?? ('API error (HTTP ' . $code . ')');

        throw new RuntimeException((string)$msg);
    }

    return $data;
}

/**
 * OpenAI responses API の text 抽出補助
 */
function ai_pick_openai_text_value($v): string
{
    if (is_string($v)) {
        return $v;
    }

    if (is_array($v)) {
        if (isset($v['value']) && is_string($v['value'])) {
            return $v['value'];
        }
        if (isset($v['text']) && is_string($v['text'])) {
            return $v['text'];
        }
    }

    return '';
}

function ai_extract_openai_output_text(array $data): string
{
    $text = trim((string)($data['output_text'] ?? ''));
    if ($text !== '') {
        return $text;
    }

    if (!is_array($data['output'] ?? null)) {
        return '';
    }

    $parts = [];

    foreach ($data['output'] as $item) {
        if (($item['type'] ?? '') !== 'message') {
            continue;
        }

        foreach (($item['content'] ?? []) as $c) {
            $type = (string)($c['type'] ?? '');

            if (($type === 'output_text' || $type === 'text') && isset($c['text'])) {
                $parts[] = ai_pick_openai_text_value($c['text']);
            }

            if ($type === 'refusal' && isset($c['refusal'])) {
                $parts[] = ai_pick_openai_text_value($c['refusal']);
            }
        }
    }

    return trim(implode("\n", array_filter($parts)));
}

/**
 * Gemini text 抽出
 */
function ai_extract_gemini_text(array $data): string
{
    $texts = [];

    foreach (($data['candidates'] ?? []) as $candidate) {
        foreach (($candidate['content']['parts'] ?? []) as $part) {
            if (isset($part['text']) && is_string($part['text'])) {
                $texts[] = $part['text'];
            }
        }
    }

    return trim(implode("\n", $texts));
}

/**
 * Claude text 抽出
 */
function ai_extract_claude_text(array $data): string
{
    $texts = [];

    foreach (($data['content'] ?? []) as $part) {
        if (($part['type'] ?? '') === 'text' && isset($part['text']) && is_string($part['text'])) {
            $texts[] = $part['text'];
        }
    }

    return trim(implode("\n", $texts));
}

/**
 * OpenAI 用の添付ファイル content を組み立てる
 * 最小変更版なので添付対応は OpenAI のみ
 */
function ai_build_openai_file_content(array $files): array
{
    $content = [];

    $maxTotalBytes = 3 * 1024 * 1024;
    $maxFiles = 2;
    $total = 0;
    $count = 0;

    $uploadDir = dirname(__DIR__) . '/uploads/task_files';

    foreach ($files as $f) {
        if ($count >= $maxFiles) {
            break;
        }

        $path = $uploadDir . '/' . (string)($f['stored_name'] ?? '');
        if (!is_file($path)) {
            continue;
        }

        $size = (int)($f['size'] ?? 0);
        if ($size <= 0) {
            continue;
        }

        if ($total + $size > $maxTotalBytes) {
            break;
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            continue;
        }

        $total += $size;
        $count++;

        $b64  = base64_encode($bytes);
        $mime = (string)(($f['mime'] ?? '') ?: 'application/octet-stream');

        $content[] = [
            'type' => 'input_file',
            'filename' => (string)($f['original_name'] ?? 'attachment'),
            'file_data' => "data:{$mime};base64,{$b64}",
        ];
    }

    return $content;
}

/**
 * ガイダンス生成: OpenAI
 */
function ai_call_openai_guidance(
    string $apiKey,
    string $model,
    string $system,
    string $userPrompt,
    array $files = []
): string {
    $content = ai_build_openai_file_content($files);
    $content[] = ['type' => 'input_text', 'text' => $userPrompt];

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
                'content' => $content,
            ],
        ],
        'text' => [
            'format' => ['type' => 'text'],
            'verbosity' => 'medium',
        ],
        'max_output_tokens' => 1400,
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

/**
 * ガイダンス生成: Gemini
 * 最小変更版ではファイル添付なし
 */
function ai_call_gemini_guidance(
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
            'temperature' => 0.4,
            'maxOutputTokens' => 1400,
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

/**
 * ガイダンス生成: Claude
 * 最小変更版ではファイル添付なし
 */
function ai_call_claude_guidance(
    string $apiKey,
    string $model,
    string $system,
    string $userPrompt
): string {
    $payload = [
        'model' => $model,
        'max_tokens' => 1400,
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

/**
 * 共通: ガイダンス生成の入口
 */
function ai_generate_guidance(
    string $provider,
    string $model,
    string $apiKey,
    string $system,
    string $userPrompt,
    array $files = []
): string {
    if ($provider === 'openai') {
        return ai_call_openai_guidance($apiKey, $model, $system, $userPrompt, $files);
    }
    if ($provider === 'gemini') {
        return ai_call_gemini_guidance($apiKey, $model, $system, $userPrompt);
    }
    if ($provider === 'claude') {
        return ai_call_claude_guidance($apiKey, $model, $system, $userPrompt);
    }

    throw new RuntimeException('Unsupported provider: ' . $provider);
}

/**
 * 採点用: スコアから grade を補完
 */
function ai_score_to_grade(int $score): string
{
    if ($score >= 90) return 'A';
    if ($score >= 80) return 'B';
    if ($score >= 70) return 'C';
    if ($score >= 60) return 'D';
    return 'E';
}

/**
 * 採点用: 文字列配列を正規化
 */
function ai_normalize_string_list($value, int $max = 6): array
{
    if (!is_array($value)) {
        return [];
    }

    $out = [];

    foreach ($value as $v) {
        $s = trim((string)$v);
        if ($s !== '') {
            $out[] = $s;
        }
        if (count($out) >= $max) {
            break;
        }
    }

    return array_values($out);
}

/**
 * 採点用: 生テキストから最初の JSON オブジェクトを抜く
 */
function ai_extract_first_json_object(string $raw): ?array
{
    $raw = trim($raw);

    if ($raw === '') {
        return null;
    }

    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/su', $raw, $m)) {
        $raw = trim($m[1]);
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $start = strpos($raw, '{');
    $end   = strrpos($raw, '}');

    if ($start === false || $end === false || $end <= $start) {
        return null;
    }

    $candidate = substr($raw, $start, $end - $start + 1);
    $decoded = json_decode($candidate, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * 採点用: 結果JSONを正規化
 */
function ai_normalize_grade_result(array $data): array
{
    $score = (int)($data['score'] ?? 0);
    $score = max(0, min(100, $score));

    $grade = strtoupper(trim((string)($data['grade'] ?? '')));
    if (!in_array($grade, ['A', 'B', 'C', 'D', 'E'], true)) {
        $grade = ai_score_to_grade($score);
    }

    $good = ai_normalize_string_list($data['good_points'] ?? []);
    $bad  = ai_normalize_string_list($data['bad_points'] ?? []);
    $next = ai_normalize_string_list($data['next_actions'] ?? []);

    if (count($good) < 3) {
        $good = array_pad($good, 3, '良い点の補足が必要です。');
    }
    if (count($bad) < 3) {
        $bad = array_pad($bad, 3, '改善点の補足が必要です。');
    }
    if (count($next) < 3) {
        $next = array_pad($next, 3, '次の行動をもう一つ具体化してください。');
    }

    $summary = trim((string)($data['summary'] ?? ''));
    if ($summary === '') {
        $summary = '総評を生成できませんでした。';
    }

    return [
        'score' => $score,
        'grade' => $grade,
        'good_points' => array_values($good),
        'bad_points' => array_values($bad),
        'next_actions' => array_values($next),
        'summary' => $summary,
    ];
}

/**
 * 採点: OpenAI
 */
function ai_call_openai_grade(
    string $apiKey,
    string $model,
    string $system,
    string $userPrompt
): array {
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
            'format' => [
                'type' => 'json_schema',
                'name' => 'grade_result',
                'schema' => ai_grade_json_schema(),
            ],
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

    $raw = ai_extract_openai_output_text($data);
    $decoded = ai_extract_first_json_object($raw);

    if (!is_array($decoded)) {
        throw new RuntimeException('OpenAI returned invalid JSON');
    }

    return ai_normalize_grade_result($decoded);
}

/**
 * 採点: Gemini
 */
function ai_call_gemini_grade(
    string $apiKey,
    string $model,
    string $system,
    string $userPrompt
): array {
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
            'temperature' => 0.2,
            'maxOutputTokens' => 1000,
            'responseMimeType' => 'application/json',
            'responseSchema' => ai_grade_json_schema(),
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

    $raw = ai_extract_gemini_text($data);
    $decoded = ai_extract_first_json_object($raw);

    if (!is_array($decoded)) {
        throw new RuntimeException('Gemini returned invalid JSON');
    }

    return ai_normalize_grade_result($decoded);
}

/**
 * 採点: Claude
 * 最小変更版では prompt で JSON を強制して、返却文字列から JSON を取り出す
 */
function ai_call_claude_grade(
    string $apiKey,
    string $model,
    string $system,
    string $userPrompt
): array {
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

    $raw = ai_extract_claude_text($data);
    $decoded = ai_extract_first_json_object($raw);

    if (!is_array($decoded)) {
        throw new RuntimeException('Claude returned invalid JSON');
    }

    return ai_normalize_grade_result($decoded);
}

/**
 * 共通: 採点の入口
 */
function ai_grade_draft(
    string $provider,
    string $model,
    string $apiKey,
    string $system,
    string $userPrompt
): array {
    if ($provider === 'openai') {
        return ai_call_openai_grade($apiKey, $model, $system, $userPrompt);
    }
    if ($provider === 'gemini') {
        return ai_call_gemini_grade($apiKey, $model, $system, $userPrompt);
    }
    if ($provider === 'claude') {
        return ai_call_claude_grade($apiKey, $model, $system, $userPrompt);
    }

    throw new RuntimeException('Unsupported provider: ' . $provider);
}
