<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WeChatOAuthController extends Controller
{
    private const CACHE_PREFIX = 'wechat_oauth_proxy:states:';

    public function redirect(Request $request): RedirectResponse
    {
        $redirectUri = $this->redirectUriFromRequest($request);
        $scope = $this->scopeFromRequest($request);
        $state = $this->stateFromRequest($request);
        $appId = $this->appIdFromRequest($request);

        Cache::put($this->cacheKey($state), [
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'app_id' => $appId,
            'created_at' => now()->toIso8601String(),
        ], now()->addMinutes($this->stateTtlMinutes()));

        $wechatAuthorizeUrl = 'https://open.weixin.qq.com/connect/oauth2/authorize?'.http_build_query([
            'appid' => $appId,
            'redirect_uri' => route('oauth.wechat.callback'),
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986).'#wechat_redirect';

        return redirect()->away($wechatAuthorizeUrl);
    }

    public function callback(Request $request): RedirectResponse
    {
        $code = trim((string) $request->query('code', ''));
        $state = trim((string) $request->query('state', ''));

        if ($code === '' || $state === '') {
            abort(422, '微信授权回调缺少 code 或 state');
        }

        $payload = Cache::get($this->cacheKey($state));
        if (! is_array($payload) || empty($payload['redirect_uri'])) {
            abort(419, '授权状态已过期，请重新发起授权');
        }

        return redirect()->away($this->appendQuery((string) $payload['redirect_uri'], [
            'code' => $code,
            'state' => $state,
        ]));
    }

    private function redirectUriFromRequest(Request $request): string
    {
        $redirectUri = $this->normalizeRedirectUri((string) $request->query('redirect_uri', ''));
        if ($redirectUri === '') {
            abort(422, '缺少 redirect_uri');
        }

        $parts = parse_url($redirectUri);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            abort(422, 'redirect_uri 必须是完整的 http/https 地址');
        }

        if (! $this->isAllowedRedirectHost($host)) {
            abort(422, 'redirect_uri host 不在允许范围内');
        }

        return $redirectUri;
    }

    private function normalizeRedirectUri(string $redirectUri): string
    {
        $redirectUri = trim($redirectUri);

        for ($i = 0; $i < 3; $i++) {
            if (! preg_match('/^(https?|HTTPS?)%3A%2F%2F/', $redirectUri)) {
                break;
            }

            $decoded = rawurldecode($redirectUri);
            if ($decoded === $redirectUri) {
                break;
            }

            $redirectUri = trim($decoded);
        }

        return $redirectUri;
    }

    private function scopeFromRequest(Request $request): string
    {
        $scope = trim((string) $request->query('scope', config('wechat_oauth.default_scope', 'snsapi_base')));
        $allowedScopes = (array) config('wechat_oauth.allowed_scopes', []);

        if (! in_array($scope, $allowedScopes, true)) {
            abort(422, '不支持的微信授权 scope');
        }

        return $scope;
    }

    private function stateFromRequest(Request $request): string
    {
        $state = trim((string) $request->query('state', ''));
        if ($state === '') {
            return Str::random(32);
        }

        if (! preg_match('/^[A-Za-z0-9._~-]{1,128}$/', $state)) {
            abort(422, 'state 格式不正确');
        }

        return $state;
    }

    private function appIdFromRequest(Request $request): string
    {
        $appId = trim((string) (config('wechat_oauth.app_id') ?: $request->query('app_id', $request->query('appid', ''))));
        if ($appId === '') {
            abort(422, '微信公众号 AppID 未配置');
        }

        return $appId;
    }

    private function isAllowedRedirectHost(string $host): bool
    {
        $allowedHosts = (array) config('wechat_oauth.allowed_redirect_hosts', []);
        if (empty($allowedHosts)) {
            return true;
        }

        foreach ($allowedHosts as $allowedHost) {
            $allowedHost = strtolower(trim((string) $allowedHost));
            if ($allowedHost === '') {
                continue;
            }

            if ($allowedHost === $host || $allowedHost === '*') {
                return true;
            }

            if (str_starts_with($allowedHost, '*.')) {
                $suffix = substr($allowedHost, 1);
                if (str_ends_with($host, $suffix) && $host !== ltrim($suffix, '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function appendQuery(string $url, array $params): string
    {
        $fragment = '';
        if (str_contains($url, '#')) {
            [$url, $fragment] = explode('#', $url, 2);
        }

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        if ($fragment !== '' && str_starts_with($fragment, '/')) {
            return $url.'#'.$fragment.(str_contains($fragment, '?') ? '&' : '?').$query;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').$query.($fragment !== '' ? '#'.$fragment : '');
    }

    private function cacheKey(string $state): string
    {
        return self::CACHE_PREFIX.$state;
    }

    private function stateTtlMinutes(): int
    {
        return max(1, (int) config('wechat_oauth.state_ttl_minutes', 10));
    }
}
