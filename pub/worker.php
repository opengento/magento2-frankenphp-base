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
} catch (\Exception $e) {
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

$bootstrapPool = new \Opengento\Application\ObjectManager\BootstrapPool($_SERVER);
$handler = static function () use ($bootstrapPool, $frankengento): void {
    try {
        $bootstrap = $bootstrapPool->get($_SERVER, $_GET);
        $app = $bootstrap->createApplication($frankengento);
        if ($app !== null) {
            $bootstrap->run($app);
        }
    } catch (\Magento\Framework\Exception\LocalizedException $e) {
        echo $e->getMessage();
        exit(1);
    }
};

$maxRequests = (int)($_SERVER['MAX_REQUESTS'] ?? 0);
$nbRequests = 1;
do {
    $keepRunning = \frankenphp_handle_request($handler);
} while ($keepRunning && (!$maxRequests || $nbRequests++ < $maxRequests));
