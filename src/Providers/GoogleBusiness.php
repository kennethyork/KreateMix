<?php
declare(strict_types=1);

namespace App\Providers;

use App\MediaTools;

/** Google Business Profile local posts. Each location is connected as its own account. */
final class GoogleBusiness extends OAuth2Provider
{
    public static function key(): string { return 'google_business'; }
    public static function name(): string { return 'Google Business Profile'; }
    public static function color(): string { return '#4285f4'; }
    public static function textLimit(): int { return 1500; }
    public static function implemented(): bool { return true; }

    public static function mediaRules(): array
    {
        return ['max_media' => 1, 'video' => false, 'requires_media' => false, 'requires_video' => false];
    }

    public static function credentialFields(): array
    {
        return ['client_id' => 'Google OAuth Client ID', 'client_secret' => 'Google OAuth Client Secret'];
    }

    public static function setupHint(): string
    {
        return 'Request Business Profile API access from Google, then in Google Cloud Console enable the My Business Account Management, Business Information and Google My Business APIs, create an OAuth web client and add the callback URL below. Each location becomes its own account. Your server must be reachable from the internet so Google can fetch images.';
    }

    protected function authorizeEndpoint(): string { return 'https://accounts.google.com/o/oauth2/v2/auth'; }
    protected function tokenEndpoint(): string { return 'https://oauth2.googleapis.com/token'; }
    protected function scopes(): array { return ['https://www.googleapis.com/auth/business.manage']; }
    protected function extraAuthParams(): array { return ['access_type' => 'offline', 'prompt' => 'consent']; }

    public function connect(array $input, string $redirectUri): array
    {
        $token = $this->exchangeCode($input, $redirectUri);
        $this->account = ['token' => $token];
        $accounts = $this->api('GET', 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts')['json']['accounts'] ?? [];
        $out = [];
        foreach ($accounts as $acct) {
            $pageToken = null;
            do {
                $r = $this->api('GET', 'https://mybusinessbusinessinformation.googleapis.com/v1/' . $acct['name'] . '/locations', ['query' => array_filter([
                    'readMask' => 'name,title,storefrontAddress',
                    'pageSize' => 100,
                    'pageToken' => $pageToken,
                ])])['json'];
                foreach ($r['locations'] ?? [] as $loc) {
                    $address = $loc['storefrontAddress']['locality'] ?? ($loc['storefrontAddress']['addressLines'][0] ?? '');
                    $out[] = [
                        'provider_id' => $loc['name'],
                        'name' => $loc['title'] ?? 'Location',
                        'username' => $address,
                        'avatar' => null,
                        'token' => $token,
                        // v4 local posts need "accounts/{a}/locations/{l}".
                        'data' => ['parent' => $acct['name'] . '/' . $loc['name']],
                    ];
                }
                $pageToken = $r['nextPageToken'] ?? null;
            } while ($pageToken);
        }
        if (!$out) {
            throw new ProviderException('No Business Profile locations were found for this Google account.');
        }
        return $out;
    }

    public function publish(string $text, array $media): array
    {
        $parent = $this->accountData()['parent'] ?? '';
        $body = [
            'languageCode' => 'en',
            'summary' => $text,
            'topicType' => 'STANDARD',
        ];
        if (preg_match('#https?://\S+#u', $text, $m)) {
            $body['callToAction'] = ['actionType' => 'LEARN_MORE', 'url' => rtrim($m[0], '.,;:!?)')];
        }
        $images = array_values(array_filter($media, fn($m) => !MediaTools::isVideo($m)));
        if ($images) {
            $img = MediaTools::jpeg($images[0], 5 * 1024 * 1024);
            MediaTools::assertPublicUrl($img['url'], 'Google');
            $body['media'] = [['mediaFormat' => 'PHOTO', 'sourceUrl' => $img['url']]];
        }
        $post = $this->api('POST', 'https://mybusiness.googleapis.com/v4/' . $parent . '/localPosts', ['json' => $body])['json'];
        return ['id' => (string) ($post['name'] ?? ''), 'url' => $post['searchUrl'] ?? null];
    }
}
