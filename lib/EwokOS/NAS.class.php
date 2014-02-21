<?php

namespace EwokOS;

class NAS implements ServiceInterface {
    public function checkSystemHealth() {
        $required_files = array(
            '/etc/exports',
            '/etc/drbd.d/r0.res',
            '/etc/corosync/corosync.conf',
            '/etc/corosync/service.d/pcmk',
        );
        
        foreach ($required_files as $file) {
            if (!file_exists($file)) {
                return false;
            }
        }
        
        // All other tests passed
        return true;
    }
    
    public function updateLocalConfiguration() {
        // All local confs depend on post-startup stuff so nothing to do here for now
        return true;
    }
    
    public function isRunning() {
        $processCount = intval(`/bin/ps -efl | grep libexec/pacemaker | grep -v grep | /usr/bin/wc -l`) - 1;
        if ($processCount > 0) {
            return true;
        } else {
            return false;
        }
    }
    
    private function getNasConfiguration() {
        $nas_vars = array();
        
        $vars = array(
            'ebs_volume_a',
            'ebs_server_a_hostname',
            'ebs_volume_b',
            'ebs_server_b_hostname',
        );
        $config = Core::loadConfiguration();
        foreach ($vars as $var) {
            if (isset($config['nas'][$var])) {
                $nas_vars[$var] = $config['nas'][$var];
            } else {
                throw new Exception('Unable to locate a necessary configuration NAS var; cannot start (var "' . $var . '"" is missing)', Exceptions\FAILED_PRESTART);
            }
        }
        
        return $nas_vars;
    }
        
    public function preStart() {
        // <Examine NFS volumes on EBS, attach if one is free>
        $ec2 = new \AmazonEC2(array('credentials' => 'nas'));
        $my_instanceId = file_get_contents('http://169.254.169.254/latest/meta-data/instance-id');
        if (!$my_instanceId) {
            throw new Exception('Unable to determine this instance\'s InstanceID using the meta-data service', Exceptions\FAILED_PRESTART);
        }
        
        $attached = false;
        $my_hostname = trim(`/bin/hostname`);
        $vars = $this->getNasConfiguration();
        foreach (array('a', 'b') as $ebs_host) {
            if ($my_hostname == $vars['ebs_server_' . $ebs_host . '_hostname']) {
                $nfs_block_device = '/dev/sdo';
                if (file_exists($nfs_block_device)) {
                    // Already mounted, let's go!
                    $attached = true;
                } else {
                    $attached = false;
                    $response = $ec2->attach_volume($my_instanceId, $vars['ebs_volume_' . $ebs_host], '/dev/sdo');
                    if ($response->isOK()) {
                        $timeout = 120;
                        do {
                            // Watch for the disk device to appear
                            if (file_exists($nfs_block_device)) {
                                $attached = true;
                            } else {
                                sleep(1);
                            }
                        } while (!$attached && $timeout-- > 0);
                    }
                }
            }
        }
        
        if (!$attached) {
            throw new Exception('Unable to attach to any designated EBS volume; cannot start', Exceptions\FAILED_PRESTART);
        }
        // </Examine NFS volumes on EBS, attach if one is free>
    }
    
    public function startService() {
        // <Start corosync>
        $cmd = '/sbin/service corosync start';
        exec($cmd, $service_output, $service_return_status);
        if ($service_return_status !== 0) {
            throw new Exception('Attempted to start Corosync but failed for an unknown reason', Exceptions\FAILED_START);
        }
        // </Start corosync>
        
        // <Start pacemaker>
        $cmd = '/sbin/service pacemaker start';
        exec($cmd, $service_output, $service_return_status);
        if ($service_return_status !== 0) {
            throw new Exception('Attempted to start Pacemaker but failed for an unknown reason', Exceptions\FAILED_START);
        }
        // </Start pacemaker>
    }
    
    public function postStart() {
        // monitor for either: pacemaker in a valid pool, or the other ebs volume being offline
        
        // <Put this node online>
        $cmd = '/usr/sbin/crm node online';
        exec($cmd, $crm_output, $crm_return_status);
        if ($crm_return_status !== 0) {
            throw new Exception('Attempted to put this node online but failed for an unknown reason', Exceptions\FAILED_START);
        }
        // </Put this node online>
        
        // <Send SNS notification>
        // @todo
        // </Send SNS notification>
    }
    
    public function preStop() {
        // <Put this node on standby>
        $cmd = '/usr/sbin/crm node standby';
        exec($cmd, $crm_output, $crm_return_status);
        if ($crm_return_status !== 0) {
            throw new Exception('Attempted to put this node on standby but failed for an unknown reason', Exceptions\FAILED_START);
        }
        // </Put this node on standby>
    }
    
    public function stopService() {
        // <Stop pacemaker>
        $cmd = '/sbin/service pacemaker stop';
        exec($cmd, $service_output, $service_return_status);
        if ($service_return_status !== 0) {
            throw new Exception('Attempted to stop Pacemaker but failed for an unknown reason', Exceptions\FAILED_START);
        }
        // </Stop pacemaker>
        
        // <Stop corosync>
        $cmd = '/sbin/service corosync stop';
        exec($cmd, $service_output, $service_return_status);
        if ($service_return_status !== 0) {
            throw new Exception('Attempted to stop Corosync but failed for an unknown reason', Exceptions\FAILED_START);
        }
        // </Stop corosync>
    }
    
    public function postStop() {
        // <Detach EBS volume>
        $ec2 = new \AmazonEC2(array('credentials' => 'nas'));
        $my_hostname = trim(`/bin/hostname`);
        $vars = $this->getNasConfiguration();
        foreach (array('a', 'b') as $ebs_host) {
            if ($my_hostname == $vars['ebs_server_' . $ebs_host . '_hostname']) {
                if (file_exists('/dev/sdo')) {
                    $response = $ec2->detach_volume($vars['ebs_volume_' . $ebs_host]);
                    break;
                }
            }
        }
        // </Detach EBS volume>
    }
    
    public function restartService() {
        $this->preStop();
        $this->stopService();
        $this->startService();
        $this->postStart();
    }
    
    public function monitorSQS() {
        // Attach to the appropriate SQS queue based on our hostname and check for noteworthy events that need a response
    }
}