# 业务项目微信授权代理转写要点

## 背景

当前仓库是一个 Laravel 版微信公众号网页授权代理，计划转写 `shinn-lancelot/WechatOauthProxy` 的核心功能。第一阶段目标不是做完整后台或高安全版本，而是让一个现有 Laravel 业务项目的手机端 H5 微信授权登录可以正常跑通。

原项目的核心价值是：只把一个代理域名配置到微信公众号“网页授权域名”，多个示例项目不再直接作为微信 OAuth 回调域名。示例项目把自己的回调地址交给代理，代理去微信拿 `code`，再把 `code/state` 转回示例项目。

## 当前实现状态

已完成第一版 MVP：

- 当前仓库已初始化 Laravel 10。
- 代理项目已实现 `GET /oauth/wechat` 和 `GET /oauth/wechat/callback`。
- 代理项目只代理 `code`，不换取 `access_token/openid`。
- 业务 API 项目已增加 `MOBILE_WECHAT_OAUTH_PROXY_URL` 兼容分支：配置代理 URL 时跳代理，未配置时保持原直连微信行为。
- 已补充代理项目 feature tests，以及业务 API 项目的代理分支测试。

## 业务项目当前授权链路

业务 API 已经是 Laravel 10 项目，已经引入 `w7corp/easywechat`，手机端登录并不是空白状态。

关键文件：

- `routes/mobile.php`
- `app/Http/Controllers/Mobile/AuthController.php`
- `config/services.php`
- `uniapp/common/request.js`
- `uniapp/pages/auth/callback.vue`

现有 H5 授权流程：

1. `uniapp/common/request.js` 在未登录或接口返回 401 时调用 `redirectToWechatLogin('base')`。
2. 前端跳到 `GET /api/mobile/v1/auth/wechat/base?campus_code=demo-campus&url=<H5 auth callback>`。
3. `Mobile\AuthController::redirectToWechatOauth()` 校验 H5 回跳地址，生成 `state`，写入缓存 `mobile_wechat_oauth:{state}`。
4. 当前代码使用 Overtrue WeChat provider 直接跳微信授权地址，微信 `redirect_uri` 是：

   ```text
   {MOBILE_WECHAT_CALLBACK_BASE_URL}/api/mobile/v1/auth/wechat/base-callback?campus_code=...&return_url=...
   ```

5. 微信回调 `base-callback` 后，`handleWechatCallback()` 用 `code` 换微信用户，按 `tenant_id + openid` 创建或更新 `members`，然后创建 Sanctum token。
6. 后端把用户重定向到 H5 授权回跳页，并在 URL 上带 `token`。
7. `uniapp/pages/auth/callback.vue` 存储 token，执行 `bootstrap()`，再回到登录前页面。

所以，业务项目已经具备“拿到 code 后换 openid 并签发 token”的能力。代理项目不需要替代这部分。

## 当前卡点

业务项目直接授权时，微信公众号看到的 `redirect_uri` 是业务 API 的域名，因此该域名必须配置在公众号网页授权域名里。现在只有一个公众号，但会有很多示例项目，不能把每个示例项目域名都作为授权域名。

另一个实际卡点是业务 API 的 `.env` 可能类似本地内网配置：

```text
MOBILE_WECHAT_CALLBACK_BASE_URL=http://api-dev.example.test:10001
MOBILE_H5_ALLOWED_REDIRECT_HOSTS=localhost,127.0.0.1,h5-dev.example.test
```

而业务项目的 `wechatCallbackUrl()` 当前强制要求 `MOBILE_WECHAT_CALLBACK_BASE_URL` 是线上 `https` 地址。这个限制在“直接对接微信”时合理，但在“通过代理域名对接微信”时会阻碍本地/内网示例项目调试。

## 推荐第一阶段方案

第一阶段只做 `code` 代理，不做 `access_token/openid` 代理。

原因：

- 业务项目已经有完整的 `code -> openid -> Member -> Sanctum token` 流程。
- 业务项目的 `WECHAT_OFFICIAL_ACCOUNT_APP_ID` 和 `WECHAT_OFFICIAL_ACCOUNT_SECRET` 已经在后端配置，不需要让代理接触 `app_secret`。
- 代理只负责满足微信公众号授权域名限制，职责更小，跑通更快。

目标链路：

```text
H5
  -> business-api /api/mobile/v1/auth/wechat/base
  -> wechat-public-auth Laravel proxy
  -> 微信 OAuth
  -> wechat-public-auth Laravel proxy callback
  -> business-api /api/mobile/v1/auth/wechat/base-callback?code=...&state=...
  -> H5 /h5/#/pages/auth/callback?token=...
```

## 代理项目 MVP 接口

建议 Laravel 转写时先实现这些最小能力。

### GET /oauth/wechat

发起微信授权。

请求参数：

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `redirect_uri` | 是 | 示例项目回调地址，例如业务项目的 `base-callback` 完整 URL |
| `scope` | 否 | `snsapi_base` 或 `snsapi_userinfo`，默认 `snsapi_base` |
| `state` | 否 | 由示例项目传入；没有则代理生成 |
| `app_id` | 否 | 第一阶段可忽略，默认使用代理配置里的公众号 AppID |

处理逻辑：

1. 校验 `redirect_uri` 是完整 URL。
2. 可选校验 `redirect_uri` host 是否在允许列表里。
3. 生成或保留 `state`。
4. 将 `state -> redirect_uri/scope/app_id` 写入缓存，TTL 10 分钟。
5. 构造微信授权 URL，微信 `redirect_uri` 固定为代理自身回调地址 `/oauth/wechat/callback`。
6. `redirect()->away($wechatAuthorizeUrl)`。

### GET /oauth/wechat/callback

接收微信回调。

请求参数：

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `code` | 是 | 微信返回的临时 code |
| `state` | 是 | 发起授权时的 state |

处理逻辑：

1. 根据 `state` 从缓存取出原始 `redirect_uri`。
2. 将 `code` 和 `state` 追加到原始 `redirect_uri`。
3. 跳回示例项目。

注意：如果 `redirect_uri` 本身已有 query，需要追加 `&code=...`；如果有 fragment，也要保留。业务项目传给代理的 `redirect_uri` 应该是 API callback，不是 H5 hash 地址，因此这里通常不会遇到复杂 hash，但 helper 最好一次写对。

### MP_verify 文件

保留一个简易方式写入或发布微信公众号域名校验文件：

- 最简单：手动把 `MP_verify_xxx.txt` 放到 Laravel `public/`。
- 稍完整：做一个后台或 Artisan 命令，把内容写到 `public/MP_verify_xxx.txt`。

第一阶段建议先手动放文件，减少后台开发量。

## 业务项目需要配合改动

业务项目现在直接用 Overtrue provider 生成微信授权 URL。接入代理后，`redirectToWechatOauth()` 需要多一个分支：

1. 仍然在业务项目内生成 `state` 并缓存现有 state payload。
2. 仍然生成业务项目自己的 callback URL：

   ```text
   {MOBILE_WECHAT_CALLBACK_BASE_URL}/api/mobile/v1/auth/wechat/{base|info}-callback?campus_code=...&return_url=...
   ```

3. 如果配置了代理地址，例如：

   ```env
   MOBILE_WECHAT_OAUTH_PROXY_URL=https://oauth.example.com/oauth/wechat
   ```

   则不要直接跳微信，而是跳代理：

   ```text
   https://oauth.example.com/oauth/wechat
     ?scope=snsapi_base
     &state=<business_state>
     &redirect_uri=<urlencoded business callback url>
   ```

4. `handleWechatCallback()` 可以基本保持不变，因为它本来就接收 `code/state`。

5. 在代理模式下，`MOBILE_WECHAT_CALLBACK_BASE_URL` 是否必须 HTTPS 要重新定义：

   - 如果业务项目是公网 HTTPS，继续要求 HTTPS。
   - 如果只是内网示例项目，允许 HTTP callback，前提是最终用户手机能访问这个地址。

建议新增配置：

```env
MOBILE_WECHAT_OAUTH_PROXY_URL=https://oauth.example.com/oauth/wechat
MOBILE_WECHAT_PROXY_ALLOW_INSECURE_CALLBACK=true
```

只在代理模式下放宽 callback base URL 校验。

## 第一阶段配置清单

代理项目：

```env
APP_URL=https://oauth.example.com
WECHAT_OFFICIAL_ACCOUNT_APP_ID=...
WECHAT_OAUTH_ALLOWED_REDIRECT_HOSTS=h5-dev.example.test,school.example.com
```

微信公众号后台：

```text
网页授权域名：oauth.example.com
```

业务 API `.env`：

```env
WECHAT_OFFICIAL_ACCOUNT_APP_ID=同一个公众号 AppID
WECHAT_OFFICIAL_ACCOUNT_SECRET=同一个公众号 Secret
MOBILE_WECHAT_OAUTH_PROXY_URL=https://oauth.example.com/oauth/wechat
MOBILE_WECHAT_CALLBACK_BASE_URL=http://api-dev.example.test:10001
MOBILE_WECHAT_PROXY_ALLOW_INSECURE_CALLBACK=true
MOBILE_H5_ALLOWED_REDIRECT_HOSTS=localhost,127.0.0.1,h5-dev.example.test
```

H5 项目：

```env
VITE_MOBILE_API_BASE=http://api-dev.example.test:10001/api/mobile/v1
VITE_CAMPUS_CODE=demo-campus
VITE_H5_BASE_PATH=/h5/
```

本地内网模式只适合测试：微信只需要访问代理域名，但用户手机最终要能访问业务 API 和 H5 内网地址。

## 要点与难点

1. **代理只拿 code，不拿 token**

   这是最短路径。业务项目保留现有会员创建、token 签发、支付所需 openid 等逻辑。

2. **state 必须由业务项目生成并透传**

   业务项目的缓存里有 `state -> campus_code/tenant_id/redirect_url/type`。代理不能自己替换掉 state，否则业务 callback 会认为 state 失效。

3. **callback URL 校验要区分直连和代理**

   如果业务项目强制 HTTPS，代理模式下微信只看代理域名，业务 callback 可以是用户浏览器可访问的示例地址。是否允许 HTTP 要由配置显式控制。

4. **return_url 编码要保持现状**

   业务项目已经处理了 H5 hash 路由，例如 `/h5/#/pages/auth/callback`。代理不要解析或改写 `return_url`，只把整个业务 callback URL 当作 opaque URL 保存和回跳。

5. **允许域名列表不要依赖 Referer**

   原 PHP 项目用 `HTTP_REFERER` 判断来源，这对示例项目可以忍，但 Laravel 转写建议校验 `redirect_uri` 的 host。低安全要求下也足够简单。

6. **Cache 驱动要能跨请求读到 state**

   代理的 `state -> redirect_uri` 和业务项目的 `mobile_wechat_oauth:{state}` 都要跨微信跳转保留。单机 file cache 可以，部署多实例时需要 Redis。

7. **小程序登录目前不是第一阶段目标**

   H5 项目里可能存在小程序端调用 `/auth/wechat/mp` 的占位逻辑，但业务 API 未必有这个接口。当前需求是 H5 微信网页授权，先不扩小程序。

8. **多校区 openid 约束已被后续迁移处理**

   初始建表里 `members.openid` 可能是全局唯一，后续迁移应改成 `(tenant_id, openid)` 局部唯一。只要迁移完整执行，业务项目的 `firstOrCreate(['tenant_id', 'openid'])` 是一致的。

## 建议实施顺序

1. 在当前空仓库初始化 Laravel 项目。
2. 实现代理项目 MVP：`/oauth/wechat`、`/oauth/wechat/callback`、配置文件、host allowlist、MP_verify 文件发布方式。
3. 给业务 API 增加 `MOBILE_WECHAT_OAUTH_PROXY_URL` 配置和代理分支。
4. 给业务 API 加测试：
   - 未配置代理时仍直接跳微信。
   - 配置代理时跳代理，并透传 `scope/state/redirect_uri`。
   - 代理模式下允许内网 HTTP callback。
   - callback 收到 code 后仍能创建 member 并返回 token。
5. 在公众号后台配置代理域名，放置 `MP_verify_xxx.txt`。
6. 用手机访问业务 H5，验证完整链路。

## 验收标准

最小验收：

- 访问业务 H5 未登录页面会进入微信授权。
- 微信授权域名只需要配置代理域名。
- 授权完成后能回到业务 H5。
- H5 local storage 中写入业务登录 token。
- `/api/mobile/v1/bootstrap` 或 `/api/mobile/v1/me` 能用该 token 正常返回当前会员。
- `members` 表出现对应 `openid` 的会员记录。

非第一阶段：

- 完整后台管理安全域名。
- 多公众号配置。
- 代理直接换 `access_token/openid`。
- 小程序 `uni.login` 登录。
