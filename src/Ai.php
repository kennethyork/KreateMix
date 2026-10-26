<?php
declare(strict_types=1);

namespace App;

/**
 * Caption writing through any OpenAI-compatible API (OpenAI, OpenRouter, Groq, Ollama, LM Studio…)
 * or Anthropic's Messages API. Configured under Integrations → AI.
 */
final class Ai
{
    public const ACTIONS = [
        'write' => 'Write a post from an idea',
        'improve' => 'Improve',
        'shorten' => 'Shorten',
        'expand' => 'Expand',
        'casual' => 'More casual',
        'professional' => 'More professional',
        'hashtags' => 'Suggest hashtags',
        'fix' => 'Fix spelling & grammar',
        'variants' => '3 variations',
    ];

    public static function enabled(): bool
    {
        $s = Settings::get('ai');
        return !empty($s['provider']) && !empty($s['model']) && (!empty($s['api_key']) || ($s['provider'] === 'openai_compatible' && !empty($s['base_url'])));
    }

    /** Runs an action and returns the generated text. */
    public static function assist(string $action, string $text, string $idea, int $limit, array $networks, string $brandVoice = ''): string
    {
        if (!isset(self::ACTIONS[$action])) {
            throw new \InvalidArgumentException('Unknown action.');
        }
        $where = $networks ? implode(', ', $networks) : 'social media';
        $system = "You write social media posts for $where. Keep each post under $limit characters. "
            . 'Reply with only the post text: no quotes around it, no preamble, no explanation. Match the language of the input. '
            . 'Use hashtags and emoji only if the input already does or the task asks for them.'
            . ($brandVoice !== '' ? "\nBrand voice to follow: $brandVoice" : '');
        $user = match ($action) {
            'write' => "Write a post about: $idea" . ($text !== '' ? "\n\nCurrent draft for context:\n$text" : ''),
            'improve' => "Make this post clearer and more engaging without changing its meaning:\n\n$text",
            'shorten' => "Shorten this post, keeping the key point:\n\n$text",
            'expand' => "Expand this post with a bit more detail or a call to action:\n\n$text",
            'casual' => "Rewrite this post in a more casual, friendly tone:\n\n$text",
            'professional' => "Rewrite this post in a more professional tone:\n\n$text",
            'hashtags' => "Suggest 5 to 8 relevant hashtags for this post. Reply with only the hashtags separated by spaces:\n\n$text",
            'fix' => "Fix spelling, grammar and punctuation in this post. Change nothing else:\n\n$text",
            'variants' => "Write 3 different versions of this post. Separate them with a line containing only ---\n\n" . ($text !== '' ? $text : $idea),
        };
        if ($action !== 'write' && trim($text) === '' && $action !== 'variants') {
            throw new \RuntimeException('Write something in the post first.');
        }
        if (($action === 'write' || $action === 'variants') && trim($idea) === '' && trim($text) === '') {
            throw new \RuntimeException('Describe what the post should be about.');
        }
        return trim(self::complete($system, $user), " \n\"");
    }

    public static function complete(string $system, string $user): string
    {
        $s = Settings::get('ai');
        if (($s['provider'] ?? '') === 'anthropic') {
            $res = Http::post('https://api.anthropic.com/v1/messages', [
                'json' => ['model' => $s['model'], 'max_tokens' => 1024, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $user]]],
                'headers' => ['x-api-key' => $s['api_key'], 'anthropic-version' => '2023-06-01'],
                'timeout' => 90,
            ]);
            if (!Http::ok($res)) {
                throw new \RuntimeException('AI request failed: ' . ($res['json']['error']['message'] ?? 'HTTP ' . $res['status']));
            }
            $out = '';
            foreach ($res['json']['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $out .= $block['text'];
                }
            }
            return $out;
        }
        $base = rtrim((string) ($s['base_url'] ?? '') ?: 'https://api.openai.com/v1', '/');
        $headers = !empty($s['api_key']) ? ['Authorization' => 'Bearer ' . $s['api_key']] : [];
        $res = Http::post($base . '/chat/completions', [
            'json' => ['model' => $s['model'], 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], 'temperature' => 0.8],
            'headers' => $headers,
            'timeout' => 120,
        ]);
        if (!Http::ok($res)) {
            throw new \RuntimeException('AI request failed: ' . ($res['json']['error']['message'] ?? 'HTTP ' . $res['status']));
        }
        return (string) ($res['json']['choices'][0]['message']['content'] ?? '');
    }
}
