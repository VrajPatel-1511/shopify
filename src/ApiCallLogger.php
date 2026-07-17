<?php

namespace Shopify;

/**
 * Sends one JSON record per outgoing Shopify API call to a Kinesis Firehose
 * delivery stream, including the stack trace of the host-application code
 * that initiated the call. Replaces the old hardcoded Slack-webhook
 * notification in ShopifyObject/QLObject.
 *
 * Logging only runs when a delivery stream is configured
 * (config('shopify_object.log_firehose_stream') or env
 * SHOPIFY_API_LOG_FIREHOSE_STREAM) and the AWS SDK is installed in the host
 * application; otherwise it is a no-op. All failures are swallowed and the
 * client uses short timeouts with no retries — logging must never break or
 * noticeably delay the actual API call.
 */
class ApiCallLogger {

    /** @var \Aws\Firehose\FirehoseClient|null */
    private static $client = null;

    /** @var bool set when client construction failed, so we only try once per process */
    private static $clientFailed = false;

    /** max stack frames stored per record */
    const MAX_TRACE_FRAMES = 25;

    /**
     * @param string      $apiType  'rest' or 'graphql'
     * @param string      $method   HTTP method
     * @param string      $url      full request URL
     * @param string|null $shopName *.myshopify.com domain the call targets
     */
    public static function log($apiType, $method, $url, $shopName = null) {
        try {
            $stream = self::streamName();
            if (empty($stream) || !class_exists('\Aws\Firehose\FirehoseClient')) {
                return;
            }
            $client = self::client();
            if ($client === null) {
                return;
            }
            $client->putRecord([
                'DeliveryStreamName' => $stream,
                'Record' => [
                    // newline-delimited JSON so Firehose -> S3 objects stay Athena-queryable
                    'Data' => json_encode(self::buildRecord($apiType, $method, $url, $shopName)) . "\n",
                ],
            ]);
        } catch (\Throwable $e) {
            // Logging must never break the API call.
        }
    }

    /**
     * Builds the record, resolving the initiating call site by walking the
     * backtrace and skipping every frame that lives inside this package.
     * Public so it can be unit-tested without a Firehose client.
     */
    public static function buildRecord($apiType, $method, $url, $shopName = null) {
        $trace = [];
        $initiator = null;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $frame) {
            $file = isset($frame['file']) ? $frame['file'] : null;
            // Skip frames inside this package — the interesting part is who called it.
            if ($file !== null && strpos($file, __DIR__) === 0) {
                continue;
            }
            $call = (isset($frame['class']) ? $frame['class'] . $frame['type'] : '')
                . (isset($frame['function']) ? $frame['function'] : '');
            $line = ($file !== null ? $file : '[internal]')
                . (isset($frame['line']) ? ':' . $frame['line'] : '');
            $entry = trim($line . ' ' . $call);
            if ($initiator === null) {
                $initiator = $entry;
            }
            $trace[] = $entry;
            if (count($trace) >= self::MAX_TRACE_FRAMES) {
                break;
            }
        }

        $entryPoint = null;
        if (PHP_SAPI === 'cli') {
            $entryPoint = isset($_SERVER['argv']) ? implode(' ', $_SERVER['argv']) : null;
        } elseif (isset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'])) {
            $entryPoint = $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'];
        }

        return [
            'logged_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'api_type' => $apiType,
            'method' => $method,
            'url' => $url,
            'shop' => $shopName,
            'app_env' => self::env('APP_ENV'),
            'app_prefix' => self::env('APP_PREFIX'),
            'hostname' => gethostname() ?: null,
            'sapi' => PHP_SAPI,
            'entry_point' => $entryPoint,
            'initiator' => $initiator,
            'stack_trace' => $trace,
        ];
    }

    private static function client() {
        if (self::$client !== null || self::$clientFailed) {
            return self::$client;
        }
        try {
            $config = [
                'region' => self::env('AWS_REGION') ?: 'us-east-2',
                'version' => '2015-08-04',
                'http' => [
                    'timeout' => 2,
                    'connect_timeout' => 1,
                ],
                'retries' => 0,
            ];
            // Only pass static credentials when both are present; otherwise let
            // the SDK's default provider chain (instance role, etc.) resolve them.
            $key = self::env('AWS_KEY');
            $secret = self::env('AWS_SECRET');
            if (!empty($key) && !empty($secret)) {
                $config['credentials'] = ['key' => $key, 'secret' => $secret];
            }
            self::$client = new \Aws\Firehose\FirehoseClient($config);
        } catch (\Throwable $e) {
            self::$clientFailed = true;
            self::$client = null;
        }
        return self::$client;
    }

    private static function streamName() {
        $stream = null;
        if (function_exists('config')) {
            try {
                $stream = config('shopify_object.log_firehose_stream');
            } catch (\Throwable $e) {
                $stream = null;
            }
        }
        if (empty($stream)) {
            $stream = self::env('SHOPIFY_API_LOG_FIREHOSE_STREAM');
        }
        return $stream;
    }

    private static function env($key) {
        if (function_exists('env')) {
            try {
                return env($key);
            } catch (\Throwable $e) {
                // fall through to getenv
            }
        }
        $value = getenv($key);
        return $value === false ? null : $value;
    }
}
