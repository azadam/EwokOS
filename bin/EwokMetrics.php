#!/usr/bin/php
<?php

define('BASE_DIR', realpath(__DIR__ . '/..'));
require_once BASE_DIR . '/lib/EwokOS.core.php';
require_once BASE_DIR . '/lib/sdk-1.5.14/sdk.class.php';
require_once BASE_DIR . '/etc/aws_config.inc.php';

spl_autoload_register(function($class) {
    if (strpos($class, 'EwokOS') !== 0) {
        return;
    }
    
    $class = str_replace('\\', '/', $class);
    $classFile = BASE_DIR . '/lib/' . $class . '.class.php';
    
    if (file_exists($classFile) && is_file($classFile)) {
        require_once $classFile;
    }
});

$metrics = new EwokOS\Metrics;
$metrics->reportMetrics();
