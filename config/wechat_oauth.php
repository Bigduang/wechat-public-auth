<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WeChat Official Account OAuth Proxy
    |--------------------------------------------------------------------------
    |
    | This application only proxies the authorization code. Downstream demo
    | projects remain responsible for exchanging the code for OpenID/token.
    |
    */

    'app_id' => env('WECHAT_OFFICIAL_ACCOUNT_APP_ID', env('WECHAT_APP_ID')),

    'default_scope' => env('WECHAT_OAUTH_DEFAULT_SCOPE', 'snsapi_base'),

    'allowed_scopes' => [
        'snsapi_base',
        'snsapi_userinfo',
    ],

    'allowed_redirect_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WECHAT_OAUTH_ALLOWED_REDIRECT_HOSTS', ''))
    ))),

    'state_ttl_minutes' => (int) env('WECHAT_OAUTH_STATE_TTL_MINUTES', 10),
];
