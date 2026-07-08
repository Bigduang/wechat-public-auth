# WeChat OAuth Proxy API

面向代码生成 agent 和子项目接入方的接口文档。本文只描述 `wechat-public-auth` 代理项目对外暴露的 HTTP 契约；业务项目仍负责用 `code + app secret` 换取 `openid/userinfo`，并完成登录、会员、支付等业务流程。

## 1. 基本信息

```text
Base URL: https://oauth.example.com
```

实际环境中把 `https://oauth.example.com` 替换为代理项目部署域名。

当前代理项目只提供 OAuth code 代理：

| Method | Path | 用途 |
| --- | --- | --- |
| `GET` | `/oauth/wechat` | 发起微信公众号网页授权 |
| `GET` | `/oauth/wechat/callback` | 微信回调代理，再转回业务项目 |
| `GET` | `/docs/api.md` | 本文档 |

代理项目不会：

- 保存或使用公众号 `secret`
- 换取 `access_token`
- 换取或返回 `openid`
- 换取或返回头像、昵称
- 创建业务会员或业务 token
- 创建微信支付订单

## 2. 环境配置

代理项目 `.env` 至少需要：

```env
APP_URL=https://oauth.example.com
CACHE_DRIVER=file

WECHAT_OFFICIAL_ACCOUNT_APP_ID=wx_your_app_id
WECHAT_OAUTH_DEFAULT_SCOPE=snsapi_base
WECHAT_OAUTH_ALLOWED_REDIRECT_HOSTS=school.example.com,h5-dev.example.test
WECHAT_OAUTH_STATE_TTL_MINUTES=10
```

字段说明：

| 环境变量 | 必填 | 说明 |
| --- | --- | --- |
| `APP_URL` | 是 | 代理项目对外 HTTPS 域名，用于生成微信回调地址 |
| `CACHE_DRIVER` | 是 | 保存 `state -> redirect_uri`，单机可用 `file`，多实例建议 Redis |
| `WECHAT_OFFICIAL_ACCOUNT_APP_ID` | 是 | 公众号 AppID |
| `WECHAT_OAUTH_DEFAULT_SCOPE` | 否 | 默认 `snsapi_base` |
| `WECHAT_OAUTH_ALLOWED_REDIRECT_HOSTS` | 否 | 业务项目回跳 host 白名单，逗号分隔；留空表示不限制 |
| `WECHAT_OAUTH_STATE_TTL_MINUTES` | 否 | state 缓存时间，默认 10 分钟，最小按 1 分钟处理 |

`WECHAT_OAUTH_ALLOWED_REDIRECT_HOSTS` 支持：

| 写法 | 含义 |
| --- | --- |
| `school.example.com` | 只允许精确 host |
| `*.example.com` | 允许子域名，不包含根域名 `example.com` |
| `*` | 允许任意 host |
| 留空 | 不限制 host |

## 3. 发起授权

```http
GET /oauth/wechat
```

### Query 参数

| 参数 | 必填 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `redirect_uri` | 是 | 无 | 业务项目 callback 完整 URL，必须是 `http` 或 `https` |
| `scope` | 否 | `WECHAT_OAUTH_DEFAULT_SCOPE` | 只允许 `snsapi_base` 或 `snsapi_userinfo` |
| `state` | 否 | 代理生成 32 位随机串 | 建议业务项目必传，用于恢复登录上下文 |
| `app_id` | 否 | 代理环境变量 | 仅当代理未配置 AppID 时兜底 |
| `appid` | 否 | 代理环境变量 | `app_id` 的兼容别名 |

`state` 格式限制：

```text
^[A-Za-z0-9._~-]{1,128}$
```

`redirect_uri` 处理规则：

- 必须是完整 URL，例如 `https://school.example.com/api/mobile/v1/auth/wechat/base-callback?campus_code=demo-campus`
- 允许 `http` 或 `https`
- 如果配置了 host 白名单，`redirect_uri` 的 host 必须命中白名单
- 可以是已 URL encode 的值；代理最多尝试解码 3 次

### 成功响应

成功时返回 `302`，`Location` 指向微信 OAuth：

```http
HTTP/1.1 302 Found
Location: https://open.weixin.qq.com/connect/oauth2/authorize?appid=wx_your_app_id&redirect_uri=https%3A%2F%2Foauth.example.com%2Foauth%2Fwechat%2Fcallback&response_type=code&scope=snsapi_base&state=mobile-login-state-001#wechat_redirect
```

同时代理会缓存：

```json
{
  "redirect_uri": "https://school.example.com/api/mobile/v1/auth/wechat/base-callback?campus_code=demo-campus",
  "scope": "snsapi_base",
  "app_id": "wx_your_app_id",
  "created_at": "2026-01-01T00:00:00+08:00"
}
```

缓存 key：

```text
wechat_oauth_proxy:states:{state}
```

### 示例：静默授权，只拿 openid

业务项目应先生成并缓存自己的 `state`：

```text
state = mobile-login-state-001
state_payload = {
  campus_code: "demo-campus",
  redirect_url: "https://h5.example.com/h5/#/pages/auth/callback",
  type: "base"
}
```

然后跳转到代理：

```bash
curl -i "https://oauth.example.com/oauth/wechat?scope=snsapi_base&state=mobile-login-state-001&redirect_uri=https%3A%2F%2Fschool.example.com%2Fapi%2Fmobile%2Fv1%2Fauth%2Fwechat%2Fbase-callback%3Fcampus_code%3Ddemo-campus"
```

预期：

- HTTP 状态为 `302`
- `Location` host 为 `open.weixin.qq.com`
- `Location` 中 `scope=snsapi_base`
- `Location` 中 `state=mobile-login-state-001`
- `Location` 中 `redirect_uri` 是代理自己的 `/oauth/wechat/callback`

### 示例：资料授权，拿 openid、头像、昵称

```bash
curl -i "https://oauth.example.com/oauth/wechat?scope=snsapi_userinfo&state=mobile-profile-state-001&redirect_uri=https%3A%2F%2Fschool.example.com%2Fapi%2Fmobile%2Fv1%2Fauth%2Fwechat%2Finfo-callback%3Fcampus_code%3Ddemo-campus"
```

预期：

- HTTP 状态为 `302`
- `Location` 中 `scope=snsapi_userinfo`
- 微信授权完成后，业务项目的 `info-callback` 用 `code + secret` 获取 `openid/userinfo`
- 代理不返回 `openid/nickname/avatar`

### 错误响应

代理使用 Laravel `abort()`，错误响应通常是 HTML，不是 JSON。agent 应优先判断 HTTP 状态码和响应文本。

| 状态码 | 场景 | 响应文本 |
| --- | --- | --- |
| `422` | 缺少 `redirect_uri` | `缺少 redirect_uri` |
| `422` | `redirect_uri` 不是完整 http/https URL | `redirect_uri 必须是完整的 http/https 地址` |
| `422` | `redirect_uri` host 未命中白名单 | `redirect_uri host 不在允许范围内` |
| `422` | `scope` 不在允许列表 | `不支持的微信授权 scope` |
| `422` | `state` 格式不正确 | `state 格式不正确` |
| `422` | AppID 未配置，且请求没有 `app_id/appid` 兜底 | `微信公众号 AppID 未配置` |

## 4. 微信回调代理

```http
GET /oauth/wechat/callback
```

这个接口只应该作为微信公众号网页授权的 `redirect_uri`。业务项目和 H5 不需要直接调用。

### Query 参数

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `code` | 是 | 微信返回的临时授权 code |
| `state` | 是 | 发起授权时传入微信的 state |

### 成功响应

成功时返回 `302`，`Location` 指向发起授权时保存的业务项目 `redirect_uri`，并追加 `code/state`。

假设发起授权时保存：

```text
redirect_uri=https://school.example.com/api/mobile/v1/auth/wechat/base-callback?campus_code=demo-campus
state=mobile-login-state-001
```

微信回调代理：

```bash
curl -i "https://oauth.example.com/oauth/wechat/callback?code=wx-code-from-wechat&state=mobile-login-state-001"
```

代理响应：

```http
HTTP/1.1 302 Found
Location: https://school.example.com/api/mobile/v1/auth/wechat/base-callback?campus_code=demo-campus&code=wx-code-from-wechat&state=mobile-login-state-001
```

### Query 追加规则

原始 `redirect_uri` 没有 query：

```text
https://school.example.com/callback
-> https://school.example.com/callback?code=CODE&state=STATE
```

原始 `redirect_uri` 已有 query：

```text
https://school.example.com/callback?campus_code=demo-campus
-> https://school.example.com/callback?campus_code=demo-campus&code=CODE&state=STATE
```

原始 `redirect_uri` 是 hash route：

```text
https://h5.example.com/h5/#/pages/auth/callback
-> https://h5.example.com/h5/#/pages/auth/callback?code=CODE&state=STATE
```

普通 fragment 不以 `/` 开头时，query 追加到 fragment 前：

```text
https://h5.example.com/callback#section
-> https://h5.example.com/callback?code=CODE&state=STATE#section
```

### 错误响应

| 状态码 | 场景 | 响应文本 |
| --- | --- | --- |
| `422` | 缺少 `code` 或 `state` | `微信授权回调缺少 code 或 state` |
| `419` | state 不存在或已过期 | `授权状态已过期，请重新发起授权` |

## 5. 业务项目接入约定

业务项目至少提供两类授权入口和两类 callback：

| 业务接口 | 微信 scope | 用途 |
| --- | --- | --- |
| `GET /api/mobile/v1/auth/wechat/base` | `snsapi_base` | 静默登录，只要求拿到 openid |
| `GET /api/mobile/v1/auth/wechat/base-callback` | 无 | 接收代理回传的 `code/state`，换 openid，登录 |
| `GET /api/mobile/v1/auth/wechat/info` | `snsapi_userinfo` | 用户确认授权，获取头像昵称 |
| `GET /api/mobile/v1/auth/wechat/info-callback` | 无 | 接收代理回传的 `code/state`，换 openid/userinfo，更新资料 |

业务项目发起授权时推荐：

1. 生成 `state`
2. 缓存业务上下文，例如 `campus_code/tenant_id/return_url/type`
3. 根据授权类型生成业务 callback URL
4. 跳转到代理 `/oauth/wechat`

示例伪代码：

```php
$state = Str::random(32);

Cache::put('mobile_wechat_oauth:'.$state, [
    'campus_code' => 'demo-campus',
    'tenant_id' => 1,
    'redirect_url' => 'https://h5.example.com/h5/#/pages/auth/callback',
    'type' => 'base',
], now()->addMinutes(10));

$callbackUrl = 'https://school.example.com/api/mobile/v1/auth/wechat/base-callback?'.http_build_query([
    'campus_code' => 'demo-campus',
], '', '&', PHP_QUERY_RFC3986);

$proxyUrl = 'https://oauth.example.com/oauth/wechat?'.http_build_query([
    'scope' => 'snsapi_base',
    'state' => $state,
    'redirect_uri' => $callbackUrl,
], '', '&', PHP_QUERY_RFC3986);

return redirect()->away($proxyUrl);
```

业务 callback 收到代理回跳后：

```text
GET /api/mobile/v1/auth/wechat/base-callback?campus_code=demo-campus&code=wx-code-from-wechat&state=mobile-login-state-001
```

业务项目应该：

1. 校验 `state`
2. 从业务缓存读取登录上下文
3. 用 `code + app secret` 调微信接口换 `openid`
4. 按 `tenant_id + openid` 创建或更新会员
5. 签发业务 token
6. 跳回 H5，例如 `https://h5.example.com/h5/#/pages/auth/callback?token=BUSINESS_TOKEN&auth=wechat`

`openid` 默认只保存在业务后端，不建议放到 URL。

## 6. 支付配合说明

代理项目当前没有支付接口。子项目如需 JSAPI 支付，应在业务后端完成：

1. 通过本代理完成微信公众号 OAuth
2. 业务后端用 `code + secret` 获取并保存 `openid`
3. 支付前确认当前会员有 `openid`
4. 业务后端使用与该 `openid` 匹配的公众号 AppID 创建 JSAPI 支付单
5. 支付页调用 `WeixinJSBridge.invoke('getBrandWCPayRequest', ...)`
6. 微信支付通知直接回调业务后端的 notify URL

重要约束：

| 项目 | 要求 |
| --- | --- |
| AppID | OAuth 获取 `openid` 的 AppID 必须和 JSAPI 支付使用的 AppID 匹配 |
| 支付授权目录 | 微信商户平台需要配置实际支付页所在域名/目录 |
| notify URL | 必须是微信可访问的公网 HTTPS URL，且通常不能带 query 参数 |
| 订单号 | 多子项目共用商户号时，建议加项目编码前缀，避免 `out_trade_no` 冲突 |

如果未来要把支付也集中到代理项目，需要新增统一支付页、统一 notify、项目配置、通知路由、查单补偿等功能；当前版本不包含这些能力。

## 7. Agent 检查清单

接入或生成代码时按这个顺序检查：

1. 代理 `.env` 已设置 `APP_URL` 和 `WECHAT_OFFICIAL_ACCOUNT_APP_ID`
2. 微信公众号后台网页授权域名指向代理域名
3. 微信域名验证文件已手动放到代理项目 `public/` 目录
4. 业务项目发起授权时传入自己的 `state`
5. 业务项目 callback URL 是完整 http/https URL
6. 业务项目 callback URL host 被代理 allowlist 允许
7. `base` 流程使用 `snsapi_base`
8. `info` 流程使用 `snsapi_userinfo`
9. 业务项目 callback 用 `code + secret` 换 `openid/userinfo`
10. H5 回调 URL 只携带业务 token，不携带 `openid`
11. 支付前会员表已有同一公众号下的 `openid`
12. 支付通知 URL 在业务项目中可公网访问

## 8. 最小验收命令

把示例域名替换为实际域名后执行：

```bash
curl -i "https://oauth.example.com/docs/api.md"
```

预期：

```text
HTTP/1.1 200 OK
```

```bash
curl -i "https://oauth.example.com/oauth/wechat?scope=snsapi_base&state=smoke-test-state&redirect_uri=https%3A%2F%2Fschool.example.com%2Fapi%2Fmobile%2Fv1%2Fauth%2Fwechat%2Fbase-callback%3Fcampus_code%3Ddemo-campus"
```

预期：

```text
HTTP/1.1 302 Found
Location: https://open.weixin.qq.com/connect/oauth2/authorize?...
```

```bash
curl -i "https://oauth.example.com/oauth/wechat/callback?code=fake-code&state=missing-state"
```

预期：

```text
HTTP/1.1 419
```
