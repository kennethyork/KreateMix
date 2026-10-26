<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Mailer;
use App\Settings;

/** Instance admin: email, AI, Canva and ads platform credentials. */
final class IntegrationsController
{
    /** group => [field => [label, type, secret?, placeholder?]] */
    public const GROUPS = [
        'smtp' => [
            'title' => 'Email (SMTP)',
            'hint' => 'Used to send scheduled reports. Leave the host empty to use PHP’s built-in mail().',
            'fields' => [
                'host' => ['Host', 'text', false, 'smtp.example.com'],
                'port' => ['Port', 'number', false, '587'],
                'encryption' => ['Encryption', 'select:tls=STARTTLS (587),ssl=SSL/TLS (465),none=None', false, ''],
                'username' => ['Username', 'text', false, ''],
                'password' => ['Password', 'password', true, ''],
                'from_email' => ['From address', 'email', false, 'reports@example.com'],
                'from_name' => ['From name', 'text', false, 'MixCreate'],
            ],
        ],
        'ai' => [
            'title' => 'AI writing',
            'hint' => 'Adds an AI button to the composer. Works with OpenAI, any OpenAI-compatible API (OpenRouter, Groq, a local Ollama or LM Studio server) or Anthropic.',
            'fields' => [
                'provider' => ['Provider', 'select:openai=OpenAI,openai_compatible=OpenAI-compatible (custom URL),anthropic=Anthropic', false, ''],
                'base_url' => ['Base URL (OpenAI-compatible only)', 'text', false, 'http://localhost:11434/v1'],
                'api_key' => ['API key', 'password', true, ''],
                'model' => ['Model', 'text', false, 'model name from your provider'],
                'brand_voice' => ['Brand voice (optional)', 'textarea', false, 'e.g. Friendly and direct, no jargon, light humor'],
            ],
        ],
        'canva' => [
            'title' => 'Canva',
            'hint' => 'Lets people import designs from their own Canva accounts. Create an integration at canva.com/developers with the design:meta:read, design:content:read and profile:read scopes, and add the redirect URL below.',
            'fields' => [
                'client_id' => ['Client ID', 'text', false, ''],
                'client_secret' => ['Client secret', 'password', true, ''],
            ],
            'callback' => 'canva/callback',
        ],
        'meta_ads' => [
            'title' => 'Meta Ads (Facebook & Instagram)',
            'hint' => 'Uses your Facebook app. Add the Marketing API product and the redirect URL below; requires the ads_read permission.',
            'fields' => [
                'app_id' => ['App ID', 'text', false, ''],
                'app_secret' => ['App secret', 'password', true, ''],
            ],
            'callback' => 'ads/callback/meta_ads',
        ],
        'google_ads' => [
            'title' => 'Google Ads',
            'hint' => 'Needs a Google Ads developer token (from your manager account’s API Center) and a Google OAuth web client with the redirect URL below.',
            'fields' => [
                'client_id' => ['OAuth client ID', 'text', false, ''],
                'client_secret' => ['OAuth client secret', 'password', true, ''],
                'developer_token' => ['Developer token', 'password', true, ''],
                'login_customer_id' => ['Manager account ID (optional)', 'text', false, '123-456-7890'],
            ],
            'callback' => 'ads/callback/google_ads',
        ],
        'tiktok_ads' => [
            'title' => 'TikTok Ads',
            'hint' => 'Create a developer app at business-api.tiktok.com with reporting access and set its redirect URL to the one below.',
            'fields' => [
                'app_id' => ['App ID', 'text', false, ''],
                'app_secret' => ['Secret', 'password', true, ''],
            ],
            'callback' => 'ads/callback/tiktok_ads',
        ],
    ];

    public function index(): void
    {
        $values = [];
        foreach (array_keys(self::GROUPS) as $g) {
            $values[$g] = Settings::get($g);
        }
        view('admin/integrations', ['title' => 'Integrations', 'groups' => self::GROUPS, 'values' => $values]);
    }

    public function save(string $group): void
    {
        if (!isset(self::GROUPS[$group])) {
            abort(404);
        }
        $current = Settings::get($group);
        foreach (self::GROUPS[$group]['fields'] as $key => [$label, $type, $secret]) {
            $v = (string) input($key, '');
            if ($secret && $v === '') {
                continue; // keep stored secret
            }
            $current[$key] = $v;
        }
        Settings::save($group, $current);
        flash('success', self::GROUPS[$group]['title'] . ' saved.');
        redirect('/admin/integrations#' . $group);
    }

    public function testEmail(): void
    {
        try {
            Mailer::send([(string) Auth::user()['email']], 'MixCreate test email', '<p>Your mail settings work.</p>');
            flash('success', 'Test email sent to ' . Auth::user()['email'] . '.');
        } catch (\Throwable $e) {
            flash('error', 'Email failed: ' . $e->getMessage());
        }
        redirect('/admin/integrations#smtp');
    }

    public function testAi(): void
    {
        try {
            $out = \App\Ai::complete('Reply with exactly: OK', 'Say OK');
            flash('success', 'AI responded: ' . str_limit(trim($out), 80));
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/integrations#ai');
    }
}
