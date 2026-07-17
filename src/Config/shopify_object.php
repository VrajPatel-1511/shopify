<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shopify Api
    |--------------------------------------------------------------------------
    |
    | This file is for setting the credentials for shopify api key and secret.
    |
    */

    'key' => env("SHOPIFY_KEY", null),
    'secret' => env("SHOPIFY_SECRET", null),
    'return_url' => env("SHOPIFY_RETURN_URL", null),
    'callback_url' => env("SHOPIFY_CALLBACK_URL", null),
    'version' => env("SHOPIFY_API_VERSION", '2022-01'),

    // Kinesis Firehose delivery stream that receives one JSON record per
    // outgoing API call (method, url, shop, initiating stack trace).
    // Leave empty to disable API-call logging entirely.
    'log_firehose_stream' => env("SHOPIFY_API_LOG_FIREHOSE_STREAM", null),
];