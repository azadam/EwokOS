<?php

namespace EwokOS\Modes;

const Unknown = 0;
const WAS = 1;
const NFS = 2;

namespace EwokOS\Exceptions;

const GENERAL = 0;
const MISSING_PREREQ = 1;

const FAILED_PRESTART = 2;
const FAILED_START = 3;
const FAILED_POSTSTART = 4;

const FAILED_PRESTOP = 2;
const FAILED_STOP = 3;
const FAILED_POSTSTOP = 4;

namespace EwokOS;

class Core {
    static function loadConfiguration() {
        $configurationFilePath = BASE_DIR . '/etc/EwokOS.ini';
        if (!file_exists($configurationFilePath) || !is_file($configurationFilePath)) {
            return false;
        }
        
        return parse_ini_file($configurationFilePath, true);
    }
}
