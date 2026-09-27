<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => ['api/*', 'storage/*', 'sanctum/csrf-cookie', 'login', 'register'],

    'allowed_methods' => ['*'],

    // السماح بنطاقات التطوير المحلي للفرونت إند (مهم جداً عند تفعيل supports_credentials)
    'allowed_origins' => [
        'http://localhost',
        'http://localhost:*',
        'http://127.0.0.1',
        'http://127.0.0.1:*',
    ],

    // نمط يسمح بأي منفذ (Port) يستخدمه Flutter Web أو أدوات التثقيب (Tunnels)
    'allowed_origins_patterns' => [
        '#^http://(localhost|127\.0\.0\.1)(:\d+)?$#',
        '#.*\.loca\.lt$#',     // في حال كنت تستخدم localtunnel
        '#.*\.ngrok.*#',      // في حال كنت تستخدم ngrok
    ],

    'allowed_headers' => ['*', 'bypass-tunnel-reminder', 'Authorization', 'Content-Type', 'X-Requested-With'],

    'exposed_headers' => ['bypass-tunnel-reminder', 'Authorization'],

    'max_age' => 86400,

    // تم تغييرها إلى true لتقبل التوكن والكوكيز
    'supports_credentials' => true,

];