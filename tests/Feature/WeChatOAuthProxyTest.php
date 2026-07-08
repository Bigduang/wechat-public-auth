<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WeChatOAuthProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'wechat_oauth.app_id' => 'wx-test-appid',
            'wechat_oauth.allowed_redirect_hosts' => ['school.example.test', 'h5.example.test'],
            'wechat_oauth.state_ttl_minutes' => 10,
        ]);
    }

    public function test_redirect_starts_wechat_authorization_and_caches_state(): void
    {
        $redirectUri = 'https://school.example.test/api/mobile/v1/auth/wechat/base-callback?campus_code=demo-campus&return_url=https%3A%2F%2Fh5.example.test%2Fh5%2F%23%2Fpages%2Fauth%2Fcallback';

        $response = $this->get('https://oauth.example.test/oauth/wechat?'.http_build_query([
            'redirect_uri' => $redirectUri,
            'scope' => 'snsapi_base',
            'state' => 'school-state-123',
        ]));

        $response->assertRedirect();

        $targetUrl = $response->headers->get('Location');
        $this->assertIsString($targetUrl);
        $this->assertStringStartsWith('https://open.weixin.qq.com/connect/oauth2/authorize?', $targetUrl);
        $this->assertStringEndsWith('#wechat_redirect', $targetUrl);

        parse_str((string) parse_url($targetUrl, PHP_URL_QUERY), $wechatQuery);

        $this->assertSame('wx-test-appid', $wechatQuery['appid'] ?? null);
        $this->assertSame('snsapi_base', $wechatQuery['scope'] ?? null);
        $this->assertSame('code', $wechatQuery['response_type'] ?? null);
        $this->assertSame('school-state-123', $wechatQuery['state'] ?? null);
        $this->assertSame('https://oauth.example.test/oauth/wechat/callback', $wechatQuery['redirect_uri'] ?? null);

        $cached = Cache::get('wechat_oauth_proxy:states:school-state-123');
        $this->assertIsArray($cached);
        $this->assertSame($redirectUri, $cached['redirect_uri'] ?? null);
        $this->assertSame('snsapi_base', $cached['scope'] ?? null);
        $this->assertSame('wx-test-appid', $cached['app_id'] ?? null);
    }

    public function test_api_documentation_is_accessible_for_agents(): void
    {
        $response = $this->get('/docs/api');

        $response->assertOk();
        $response->assertSee('GET /oauth/wechat', false);
        $response->assertSee('GET /oauth/wechat/callback', false);
        $response->assertSee('/docs/api.md', false);
    }

    public function test_redirect_generates_state_when_missing(): void
    {
        $response = $this->get('https://oauth.example.test/oauth/wechat?'.http_build_query([
            'redirect_uri' => 'https://school.example.test/api/mobile/v1/auth/wechat/base-callback',
        ]));

        $response->assertRedirect();

        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $wechatQuery);
        $state = (string) ($wechatQuery['state'] ?? '');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $state);
        $this->assertIsArray(Cache::get('wechat_oauth_proxy:states:'.$state));
    }

    public function test_redirect_allows_userinfo_scope_and_callback_only_forwards_code_state(): void
    {
        $redirectUri = 'https://school.example.test/api/mobile/v1/auth/wechat/info-callback?campus_code=demo-campus';

        $response = $this->get('https://oauth.example.test/oauth/wechat?'.http_build_query([
            'redirect_uri' => $redirectUri,
            'scope' => 'snsapi_userinfo',
            'state' => 'school-info-state',
        ]));

        $response->assertRedirect();

        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $wechatQuery);

        $this->assertSame('snsapi_userinfo', $wechatQuery['scope'] ?? null);
        $this->assertSame('school-info-state', $wechatQuery['state'] ?? null);

        $cached = Cache::get('wechat_oauth_proxy:states:school-info-state');
        $this->assertIsArray($cached);
        $this->assertSame($redirectUri, $cached['redirect_uri'] ?? null);
        $this->assertSame('snsapi_userinfo', $cached['scope'] ?? null);

        $callbackResponse = $this->get('/oauth/wechat/callback?'.http_build_query([
            'code' => 'wx-info-code',
            'state' => 'school-info-state',
        ]));

        $callbackResponse->assertRedirect();

        $targetUrl = (string) $callbackResponse->headers->get('Location');
        $this->assertSame($redirectUri.'&code=wx-info-code&state=school-info-state', $targetUrl);
        $this->assertStringNotContainsString('openid=', $targetUrl);
        $this->assertStringNotContainsString('nickname=', $targetUrl);
        $this->assertStringNotContainsString('avatar=', $targetUrl);
    }

    public function test_callback_redirects_code_and_state_back_to_original_redirect_uri(): void
    {
        Cache::put('wechat_oauth_proxy:states:school-state-456', [
            'redirect_uri' => 'https://school.example.test/api/mobile/v1/auth/wechat/base-callback?campus_code=demo-campus',
            'scope' => 'snsapi_base',
            'app_id' => 'wx-test-appid',
        ], now()->addMinutes(10));

        $response = $this->get('/oauth/wechat/callback?'.http_build_query([
            'code' => 'wx-code-from-wechat',
            'state' => 'school-state-456',
        ]));

        $response->assertRedirect('https://school.example.test/api/mobile/v1/auth/wechat/base-callback?campus_code=demo-campus&code=wx-code-from-wechat&state=school-state-456');
    }

    public function test_callback_appends_query_inside_hash_route_redirect_uri(): void
    {
        Cache::put('wechat_oauth_proxy:states:hash-state', [
            'redirect_uri' => 'https://h5.example.test/h5/#/pages/auth/callback',
            'scope' => 'snsapi_base',
            'app_id' => 'wx-test-appid',
        ], now()->addMinutes(10));

        $response = $this->get('/oauth/wechat/callback?'.http_build_query([
            'code' => 'wx-code',
            'state' => 'hash-state',
        ]));

        $response->assertRedirect('https://h5.example.test/h5/#/pages/auth/callback?code=wx-code&state=hash-state');
    }

    public function test_redirect_rejects_unallowed_redirect_host(): void
    {
        $this->get('/oauth/wechat?'.http_build_query([
            'redirect_uri' => 'https://evil.example.test/callback',
            'state' => 'blocked-state',
        ]))->assertStatus(422);

        $this->assertNull(Cache::get('wechat_oauth_proxy:states:blocked-state'));
    }

    public function test_redirect_rejects_unsupported_scope(): void
    {
        $this->get('/oauth/wechat?'.http_build_query([
            'redirect_uri' => 'https://school.example.test/callback',
            'scope' => 'snsapi_login',
            'state' => 'bad-scope-state',
        ]))->assertStatus(422);
    }

    public function test_callback_rejects_expired_or_unknown_state(): void
    {
        $this->get('/oauth/wechat/callback?'.http_build_query([
            'code' => 'wx-code',
            'state' => 'missing-state',
        ]))->assertStatus(419);
    }
}
