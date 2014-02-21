<?php

namespace EwokOS;

if (!defined('BASE_DIR')) exit;

class SelfUpdater {
    private static $cmds = null;
    private function initialize() {
        if (is_array(self::$cmds)) {
            return;
        }
        
        $cmds = array();
        if (file_exists('/bin/rm')) {
            $cmds['rm'] = '/bin/rm';
        } elseif (file_exists('/usr/bin/rm')) {
            $cmds['rm'] = '/usr/bin/rm';
        } else {
            throw new Exception('Missing prerequisite tool (rm)', Exceptions\MISSING_PREREQ);
        }
        if (file_exists('/bin/svn')) {
            $cmds['svn'] = '/bin/svn';
        } elseif (file_exists('/usr/bin/svn')) {
            $cmds['svn'] = '/usr/bin/svn';
        } else {
            throw new Exception('Missing prerequisite tool (svn)', Exceptions\MISSING_PREREQ);
        }
        if (file_exists('/bin/git')) {
            $cmds['git'] = '/bin/git';
        } elseif (file_exists('/usr/bin/git')) {
            $cmds['git'] = '/usr/bin/git';
        } else {
            throw new Exception('Missing prerequisite tool (git)', Exceptions\MISSING_PREREQ);
        }
        if (file_exists('/bin/rsync')) {
            $cmds['rsync'] = '/bin/rsync';
        } elseif (file_exists('/usr/bin/rsync')) {
            $cmds['rsync'] = '/usr/bin/rsync';
        } else {
            throw new Exception('Missing prerequisite tool (rsync)', Exceptions\MISSING_PREREQ);
        }
        
        self::$cmds = $cmds;
    }
    
    private function getVersionForDirectory($directory) {
        if (file_exists($directory . '/VERSION.txt')) {
            return file_get_contents($directory . '/VERSION.txt');
        } else {
            return false;
        }
    }
    
    public function downloadRepository() {
        if (self::$_REPO_DIRS === null) {
            self::$_REPO_DIRS = array();
            register_shutdown_function(array($this, 'cleanDownloads'));
        }
        
        $this->initialize();
        
        $repoCheckoutDir = self::$_REPO_DIRS[] = tempnam(sys_get_temp_dir(), 'EwokOS-Update-');
        unlink($repoCheckoutDir);
        
        $config = Core::loadConfiguration();
        $cmd = escapeshellarg(self::$cmds['git']) . ' clone ' . escapeshellarg($config['repo']['git_path']) . ' ' . escapeshellarg($repoCheckoutDir);
        `$cmd`;
        
        if (is_dir($repoCheckoutDir)) {
            return $repoCheckoutDir;
        } else {
            return false;
        }
    }
    
    private static $_REPO_DIRS = null;
    public static function cleanDownloads() {
        foreach (self::$_REPO_DIRS as $_REPO_DIRS) {
            $cmd = escapeshellarg(self::$cmds['rm']) . ' -rf ' . escapeshellarg($_REPO_DIRS);
            `$cmd`;
        }
    }
    
    public function getLocalVersion() {
        return $this->getVersionForDirectory(BASE_DIR);
    }
    
    public function updateRequired() {
        $this->initialize();
        
        $repoCheckoutDir = $this->downloadRepository();
        if (!$repoCheckoutDir) {
            return false;
        }
        
        $localVersion = $this->getLocalVersion();
        $repoVersion = $this->getVersionForDirectory($repoCheckoutDir);
        
        if ($localVersion == $repoVersion) {
            return false;
        } else {
            return array(
                'localVersion' => $localVersion,
                'repoVersion' => $repoVersion,
                'repoCheckoutDir' => $repoCheckoutDir,
            );
        }
    }
    
    public function performSelfUpdate($repoCheckoutDir) {
        $this->initialize();
        
        $cmd = escapeshellarg(self::$cmds['rsync']) . ' -av --delete ' . escapeshellarg($repoCheckoutDir . '/') . ' ' . escapeshellarg(BASE_DIR . '/');
        `$cmd`;
        
        $localVersion = $this->getLocalVersion();
        $repoVersion = $this->getVersionForDirectory($repoCheckoutDir);
        
        return ($localVersion == $repoVersion);
    }
    
    public function performPostUpdateTasks() {
        return true;
    }
}
