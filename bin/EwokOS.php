#!/usr/bin/php
<?php

define('BASE_DIR', realpath(__DIR__ . '/..'));
require_once BASE_DIR . '/lib/EwokOS.core.php';
require_once BASE_DIR . '/lib/sdk-1.5.14/sdk.class.php';
require_once BASE_DIR . '/etc/aws_config.inc.php';

function usage() {
    global $argv;
    echo "Usage: $argv[0] (was|nas) (start|stop|cron|selfupdate|health)\n";
    exit;
}

if (!defined('BASE_DIR')) exit;

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

if ($argc < 3) {
    usage();
}

$mode = false;
switch(strtolower($argv[1])) {
    case 'was':
        $service = new EwokOS\WAS();
        break;
    case 'nas':
        $service = new EwokOS\NAS();
        break;
    default:
        usage();
}

switch (strtolower($argv[2])) {
    case 'start':
        if ($service->isRunning()) {
            echo "Service is already running\n";
            exit(0);
        }
        
        try {
            $service->preStart();
            $service->startService();
            $service->postStart();
        } catch (EwokOS\Exception $e) {
            /**
             * The three phases of start and stop are reciprocal so if we fail at a particular stage,
             * reverse the process to try to back out of a partial start.  For any other exception, just
             * exit out.
             */
            
            try {
                switch ($e->getCode()) {
                    case EwokOS\Exceptions\FAILED_POSTSTART:
                        $service->preStop();
                    case EwokOS\Exceptions\FAILED_START:
                        $service->stopService();
                    case EwokOS\Exceptions\FAILED_PRESTART:
                        $service->postStop();
                    default:
                        echo "Service could not start: {$e->getMessage()} ({$e->getCode()})\n";
                        exit(1);
                }
            } catch (EwokOS\Exception $e2) {
                echo "We could neither start nor cleanly stop this service:\n";
                echo "    start exception: {$e->getMessage()} ({$e->getCode()})\n";
                echo "    stop exception: {$e2->getMessage()} ({$e2->getCode()})\n";
                exit(1);
            }
        }
        
        if ($service->checkSystemHealth()) {
            echo "Service started\n";
            exit(0);
        } else {
            echo "Unable to start; health check failed\n";
            exit(1);
        }
        
        break;
    case 'stop':
        if (!$service->isRunning()) {
            echo "Service is already stopped\n";
            exit(0);
        }
        
        try {
            $service->preStop();
            $service->stopService();
            $service->postStop();
        } catch (EwokOS\Exception $e) {
            echo "Service could not stop: {$e->getMessage()} ({$e->getCode()})\n";
            exit(1);
        }
        
        echo "Service stopped\n";
        exit(0);
        
        break;
    case 'selfupdate':
        $updater = new EwokOS\SelfUpdater;
        $arrUpdateInfo = $updater->updateRequired();
        
        if (isset($argv[3]) && $argv[3] == 'postupdatetasks') {
            $updater->performPostUpdateTasks();
        } elseif ($arrUpdateInfo) {
            echo "An update is required\n";
            if ($updater->performSelfUpdate($arrUpdateInfo['repoCheckoutDir'])) {
                echo "Update complete, restarting self\n";
                
                $cmd = '';
                for ($i=0; $i<$argc; $i++) {
                    $cmd .= escapeshellarg($argv[$i]) . ' ';
                }
                $cmd .= ' postupdatetasks';
                passthru($cmd);
            } else {
                echo "An error occurred performing a self update\n";
                exit(1);
            }
        } else {
            echo "No updates or actions required\n";
            exit(0);
        }
        
        break;
    case 'health':
        if ($service->isRunning()) {
            if ($service->checkSystemHealth()) {
                echo "Health check passed\n";
                exit(0);
            } else {
                echo "Health check failed\n";
                exit(1);
            }
        } else {
            echo "Service is not running, can't check health\n";
            exit(1);
        }
        
        break;
    case 'cron':
        if ($service->isRunning()) {
            $service->monitorSQS();
            $service->checkConfigurationUpdates();
        }
        break;
    default:
        usage();
}


echo "Ok\n";
