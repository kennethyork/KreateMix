<?php
declare(strict_types=1);

namespace App;

/**
 * Caption writing. Default provider is OpenRouter (bring your own key; one key reaches every major model).
 * Also supports OpenAI, any OpenAI-compatible server (Ollama, LM Studio, Groq…) and Anthropic.
 * Instance settings live under Integrations → AI; a workspace can override the key and model.
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

    public const OPENROUTER = 'https://openrouter.ai/api/v1';

    /** Effective settings: instance AI settings, with a workspace's own OpenRouter key/model on top. */
    public static function config(?int $workspaceId = null): array
    {
        $s = Settings::get('ai');
        $s['provider'] = ($s['provider'] ?? '') ?: 'openrouter';
        if ($workspaceId) {
            $ws = Settings::get('ai.ws.' . $workspaceId);
            if (!empty($ws['api_key'])) {
                // A workspace key always means OpenRouter (each client brings their own key).
                $s = ['provider' => 'openrouter', 'api_key' => $ws['api_key'], 'model' => ($ws['model'] ?? '') ?: (($s['provider'] === 'openrouter' ? $s['model'] ?? '' : '') ?: 'openrouter/auto'), 'brand_voice' => $s['brand_voice'] ?? ''];
            } elseif (!empty($ws['model']) && $s['provider'] === 'openrouter') {
                $s['model'] = $ws['model'];
            }
            if (!empty($ws['brand_voice'])) {
                $s['brand_voice'] = $ws['brand_voice'];
            }
        }
        if ($s['provider'] === 'openrouter' && empty($s['model'])) {
            $s['model'] = 'openrouter/auto';
        }
        return $s;
    }

    public static function enabled(?int $workspaceId = null): bool
    {
        $s = self::config($workspaceId);
        return !empty($s['model']) && (!empty($s['api_key']) || ($s['provider'] === 'openai_compatible' && !empty($s['base_url'])));
    }

    /** OpenRouter's model list, cached for a day. [['id', 'name', 'context', 'prompt_price', 'completion_price']] */
    public static function openRouterModels(): array
    {
        $file = BASE_PATH . '/storage/openrouter-models.json';
        if (is_file($file)) {
            $cached = json_decode((string) file_get_contents($file), true) ?: [];
            // Keep a good list for a day; retry a failed fetch after an hour.
            if (filemtime($file) > time() - ($cached ? 86400 : 3600)) {
                return $cached;
            }
        }
        try {
            $res = Http::get(self::OPENROUTER . '/models', ['timeout' => 8]);
        } catch (\Throwable) {
            $res = ['json' => []];
        }
        $models = [];
        foreach ($res['json']['data'] ?? [] as $m) {
            if (!in_array('text', $m['architecture']['output_modalities'] ?? ['text'], true)) {
                continue;
            }
            $models[] = [
                'id' => $m['id'],
                'name' => $m['name'] ?? $m['id'],
                'context' => (int) ($m['context_length'] ?? 0),
                'prompt_price' => isset($m['pricing']['prompt']) ? (float) $m['pricing']['prompt'] * 1e6 : null,
                'completion_price' => isset($m['pricing']['completion']) ? (float) $m['pricing']['completion'] * 1e6 : null,
            ];
        }
        usort($models, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        if ($models || !is_file($file)) {
            @file_put_contents($file, json_encode($models));
        } else {
            @touch($file); // keep the old list, try again later
            return json_decode((string) file_get_contents($file), true) ?: [];
        }
        return $models;
    }

    /** Runs an action and returns the generated text. */
    public static function assist(string $action, string $text, string $idea, int $limit, array $networks, string $brandVoice = '', ?int $workspaceId = null): string
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
        return trim(self::complete($system, $user, $workspaceId), " \n\"");
    }

    public static function complete(string $system, string $user, ?int $workspaceId = null): string
    {
        $s = self::config($workspaceId);
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
        $base = match ($s['provider']) {
            'openrouter' => self::OPENROUTER,
            'openai' => 'https://api.openai.com/v1',
            default => rtrim((string) ($s['base_url'] ?? ''), '/') ?: 'https://api.openai.com/v1',
        };
        $headers = !empty($s['api_key']) ? ['Authorization' => 'Bearer ' . $s['api_key']] : [];
        if ($s['provider'] === 'openrouter') {
            // Optional app attribution headers OpenRouter shows on its dashboard.
            $headers['HTTP-Referer'] = abs_url('');
            $headers['X-Title'] = (string) Config::get('app_name', 'MixCreate');
        }
        $res = Http::post($base . '/chat/completions', [
            'json' => ['model' => $s['model'], 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], 'temperature' => 0.8],
            'headers' => $headers,
            'timeout' => 120,
        ]);
        if (!Http::ok($res)) {
            $msg = $res['json']['error']['message'] ?? 'HTTP ' . $res['status'];
            if ($res['status'] === 402) {
                $msg = 'Your OpenRouter account is out of credits. Add credits or connect your own provider keys at openrouter.ai/settings/integrations.';
            }
            throw new \RuntimeException('AI request failed: ' . $msg);
        }
        $content = $res['json']['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) { // some providers return content parts
            $content = implode('', array_map(fn($p) => $p['text'] ?? '', $content));
        }
        return (string) $content;
    }
}
