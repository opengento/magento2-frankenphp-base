<?php
/**
 * Copyright © OpenGento, All rights reserved.
 * See LICENSE bundled with this library for license details.
 */
declare(strict_types=1);

if (!($_SERVER['FRANKENPHP_WORKER_ENABLE'] ?? false)) {
    echo <<<HTML
<div style="font:12px/1.35em arial, helvetica, sans-serif;">
    <div style="margin:0 0 25px 0; border-bottom:1px solid #ccc;">
        <h3 style="margin:0;font-size:1.7em;font-weight:normal;text-transform:none;text-align:left;color:#2f2f2f;">
        FrankenPHP Worker mode is not enabled</h3>
    </div>
</div>
HTML;
    http_response_code(500);
    exit(1);
}

try {
    require __DIR__ . '/../app/bootstrap.php';
} catch (\Throwable $e) {
    // Autoload failure happens before the request loop — the worker cannot
    // function, so exit(1) is correct. We catch \Throwable (not just \Exception)
    // to also handle \Error and \ParseError from broken autoload chains.
    echo <<<HTML
<div style="font:12px/1.35em arial, helvetica, sans-serif;">
    <div style="margin:0 0 25px 0; border-bottom:1px solid #ccc;">
        <h3 style="margin:0;font-size:1.7em;font-weight:normal;text-transform:none;text-align:left;color:#2f2f2f;">
        Autoload error</h3>
    </div>
    <p>{$e->getMessage()}</p>
</div>
HTML;
    http_response_code(500);
    exit(1);
}

$bootstrapPool = new \Opengento\Application\ObjectManager\BootstrapPool();
$handler = static function () use ($bootstrapPool, $frankengento): void {
    try {
        $bootstrap = $bootstrapPool->get();
        $app = $bootstrap->createApplication($frankengento);
        if ($app !== null) {
            $bootstrap->run($app);
        }
    } catch (\Throwable $e) {
        // Catch \Throwable (not just LocalizedException) so a single bad request
        // can never tear down the worker process. exit() inside the handler would
        // kill the entire PHP worker, forcing FrankenPHP to respawn and re-bootstrap
        // Magento — the exact failure mode worker mode is meant to prevent.
        //
        // The exception is written directly to STDERR so it's captured by
        // FrankenPHP / container log drivers regardless of how the operator
        // configured php.ini's error_log directive. A generic 500 is returned;
        // $e->getMessage() is intentionally NOT echoed to the response body
        // because it can leak filesystem paths, SQL, credentials, etc.
        if (!headers_sent()) {
            http_response_code(500);
        }
        fwrite(STDERR, sprintf(
            "[opengento/frankenphp-base] Uncaught %s in worker handler: %s at %s:%d\n",
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }
};

$maxRequests = (int)($_SERVER['MAX_REQUESTS'] ?? 0);
$nbRequests = 1;
do {
    $keepRunning = \frankenphp_handle_request($handler);
} while ($keepRunning && !$maxRequests && $nbRequests++ < $maxRequests);
