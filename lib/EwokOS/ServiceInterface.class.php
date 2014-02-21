<?php

namespace EwokOS;

interface ServiceInterface {
    public function checkSystemHealth();
    
    public function updateLocalConfiguration();
    public function isRunning();
    
    public function preStart();
    public function startService();
    public function postStart();
    
    public function preStop();
    public function stopService();
    public function postStop();
    
    public function restartService();
    
    public function monitorSQS();
}