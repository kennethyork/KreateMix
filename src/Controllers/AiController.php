<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Ai;
use App\Db;
use App\Providers\Registry;
use App\Settings;
use App\Workspace;

final class AiController
{
    public function assist(): void
    {
        if (!Ai::enabled(Workspace::id())) {
            json_response(['error' => 'AI isn’t set up. Add an OpenRouter key in workspace settings, or ask your admin to add one under Integrations.'], 422);
        }
        $action = (string) input('action', '');
        $text = (string) input('text', '');
        $idea = (string) input('idea', '');
        $ids = array_map('intval', (array) input('accounts', []));
        $networks = [];
        $limit = 2200;
        if ($ids) {
            $rows = Db::all('SELECT DISTINCT provider FROM accounts WHERE workspace_id = ? AND id IN (' . implode(',', $ids) . ')', [Workspace::id()]);
            foreach ($rows as $r) {
                if ($c = Registry::class($r['provider'])) {
                    $networks[] = $c::name();
                    $limit = min($limit, $c::textLimit());
                }
            }
        }
        try {
            $out = Ai::assist($action, $text, $idea, $limit, $networks, (string) (Ai::config(Workspace::id())['brand_voice'] ?? ''), Workspace::id());
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
        $variants = $action === 'variants' ? array_values(array_filter(array_map('trim', preg_split('/^\s*-{3,}\s*$/m', $out)))) : [$out];
        json_response(['text' => $out, 'variants' => $variants]);
    }
}
