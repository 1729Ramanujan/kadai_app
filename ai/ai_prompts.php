<?php

declare(strict_types=1);

/**
 * 共通：締切表示
 */
function ai_due_text(?string $dueAt): string
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

/**
 * ガイダンス生成用 system prompt
 */
function ai_guidance_system_prompt(): string
{
    return <<<SYS
あなたは大学生向けの学習支援AIです。
役割は、学習者が課題を理解し、自力で考えて解けるよう支援することです。

次の方針を必ず守ってください。
- 出力は日本語
- まず課題の意図を整理する
- その後、考える順序や観点をわかりやすく示す
- 必要に応じて途中式・根拠・注意点を示す
- 模範解答、完成答案、提出用の完成文は作らない
- 「答えは○○です」のように最終答えを直接断定しない
- そのまま提出できる文章を書かない
- 学習者が次に何を考えるべきかが分かる形を優先する
- 参考文献や参考情報を挙げる場合は、存在が怪しいものを捏造しない
- 不明な点がある場合は、その不明点を明示する
SYS;
}

/**
 * ガイダンス生成用 user prompt
 */
function ai_build_guidance_user_prompt(array $task): string
{
    $title  = (string)($task['title'] ?? '');
    $detail = trim((string)($task['detail'] ?? ''));
    $status = (string)($task['status'] ?? '');
    $due    = ai_due_text($task['due_at'] ?? null);

    return <<<TXT
以下の課題について、学習支援用のガイダンスを作成してください。

【課題タイトル】
{$title}

【締切】
{$due}

【状態】
{$status}

【課題詳細】
{$detail}

次の構成で、見出しを付けて出力してください。

1. 課題の要約
- 3〜6行程度で、この課題で何が問われているかを整理する

2. 考える順序
- 箇条書きで最大5個
- どの順番で考えるべきかをわかりやすく示す

3. 重要な観点・根拠・注意点
- 必要な範囲で説明する
- 数学・理科・レポートなど課題の種類に応じて適切に説明する
- ただし完成答案や最終答えそのものは書かない

4. 自分で進めるための次の一歩
- 学習者が次に確認・調査・検討するとよいことを2〜4個挙げる

5. 参考文献・参考情報
- 最大3件
- 捏造は禁止
- 自信がない場合は「参考文献なし」と書いてよい
TXT;
}
/**
 * 採点用 system prompt
 */
function ai_grade_system_prompt(): string
{
    return <<<SYS
あなたは大学課題の採点者です。
受講者の答案を厳密かつ建設的に評価してください。

必ず有効なJSONのみを返してください。
- 説明文は禁止
- Markdown禁止
- コードブロック禁止
- JSONオブジェクトのみ出力
SYS;
}

/**
 * 採点用 user prompt
 */
function ai_build_grade_user_prompt(array $task, string $draft): string
{
    $title  = (string)($task['title'] ?? '');
    $detail = trim((string)($task['detail'] ?? ''));
    $status = (string)($task['status'] ?? '');
    $due    = ai_due_text($task['due_at'] ?? null);
    $draft  = trim($draft);

    return <<<TXT
次の課題に対して、受講者の提出答案（下書き）を採点してください。

【課題タイトル】
{$title}

【締切】
{$due}

【状態】
{$status}

【課題文 / 詳細】
{$detail}

【提出答案（下書き）】
{$draft}

採点基準（合計100点）:
- 正確性 30
- 論理性 25
- 網羅性 20
- 具体性 15
- 表現 / 読みやすさ 10

成績評価:
A: 90-100
B: 80-89
C: 70-79
D: 60-69
E: 0-59

必ず次のJSONスキーマに従ってください。
{
  "score": 0から100までの整数,
  "grade": "A" または "B" または "C" または "D" または "E",
  "good_points": ["良い点1", "良い点2", "良い点3"],
  "bad_points": ["改善点1", "改善点2", "改善点3"],
  "next_actions": ["次にやる具体的行動1", "次にやる具体的行動2", "次にやる具体的行動3"],
  "summary": "1〜2文の総評"
}

条件:
- good_points は 3〜6個
- bad_points は 3〜6個
- next_actions は 3〜6個
- score は整数
- summary は簡潔に
TXT;
}

/**
 * OpenAI / Gemini structured output 用の JSON Schema
 */
function ai_grade_json_schema(): array
{
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => [
            'score',
            'grade',
            'good_points',
            'bad_points',
            'next_actions',
            'summary',
        ],
        'properties' => [
            'score' => [
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => 100,
            ],
            'grade' => [
                'type' => 'string',
                'enum' => ['A', 'B', 'C', 'D', 'E'],
            ],
            'good_points' => [
                'type' => 'array',
                'minItems' => 3,
                'maxItems' => 6,
                'items' => [
                    'type' => 'string',
                ],
            ],
            'bad_points' => [
                'type' => 'array',
                'minItems' => 3,
                'maxItems' => 6,
                'items' => [
                    'type' => 'string',
                ],
            ],
            'next_actions' => [
                'type' => 'array',
                'minItems' => 3,
                'maxItems' => 6,
                'items' => [
                    'type' => 'string',
                ],
            ],
            'summary' => [
                'type' => 'string',
                'minLength' => 1,
            ],
        ],
    ];
}
