<?php

namespace EwokOS;

class WAS implements ServiceInterface {
    private $stateVariables = null;
    
    private function getStateVariable($key) {
        if ($this->stateVariables == null) {
            $this->loadStateVariables();
        }
        
        if (isset($this->stateVariables[$key])) {
            return $this->stateVariables[$key];
        } else {
            return null;
        }
    }
    
    private function setStateVariable($key, $value) {
        $this->stateVariables[$key] = $value;
        $this->writeStateVariables();
    }
    
    private function loadStateVariables() {
        if (!file_exists($this->getStateVariableFilename())) {
            $this->stateVariables = array();
        } else {
            $this->stateVariables = unserialize(file_get_contents($this->getStateVariableFilename()));
        }
    }
    
    private function writeStateVariables() {
        file_put_contents($this->getStateVariableFilename(), serialize($this->stateVariables));
    }
    
    private function getStateVariableFilename() {
        return sys_get_temp_dir() . '/EwokOS-StateVariables-' . md5(__FILE__) . '.txt';
    }
    
    
    public function checkSystemHealth() {
        if (!file_exists('/etc/php.ini')) {
            return false;
        }
        
        // <Check to see if NFS mount is healthy>
        // Send ourselves a signal in case this blocks
        declare(ticks = 1);
        pcntl_signal(SIGALRM, function($signal) {
            `/bin/ps -u root -f | /bin/grep /mnt/nas | /bin/grep ls | /bin/awk ' { print "kill -9 "\$2 } ' | /bin/sh`;
        });
        pcntl_alarm(20);
        
        $cmd = '/bin/ls /mnt/nas';
        exec($cmd, $ls_output, $ls_return_status);
        if ($ls_return_status === 137) {
            // We were killed; must have taken longer than 20 seconds
            return false;
         }
        // </Check to see if NFS mount is healthy>
        
        // <Check to see if Apache is healthy>
        /*
        // Bypass this check for now
        $server_status = file_get_contents('http://localhost/server-status');
        if (strpos($server_status, 'Server uptime') === false) {
            // So rudimentary, just look for the key phrase in our server-status output, if there is any output
            return false;
        }
        */
        // </Check to see if Apache is healthy>
         
        // Check any other misc system metrics for general health
        
        // All other tests passed
        return true;
    }
    
    public function updateLocalConfiguration() {
        // This will all run off of the NAS so shouldn't need any local configurations yet
        return true;
    }
    
    public function isRunning() {
        $processCount = intval(`/bin/ps -u apache | /usr/bin/wc -l`) - 1;
        if ($processCount > 0) {
            return true;
        } else {
            return false;
        }
    }
    
    public function preStart() {
        // <Grab NAS config information>
        $config = Core::loadConfiguration();
        if (isset($config['nas']['nfs_eip_hostname'])) {
            $nfs_eip_hostname = $config['nas']['nfs_eip_hostname'];
        } else {
            throw new Exception('Unable to locate NAS Elastic IP information; cannot start', Exceptions\FAILED_PRESTART);
        }
        
        /*
        $ec2 = new \AmazonEC2(array('credentials' => 'was'));
        $response = $ec2->describe_addresses();
        
        $nfs_eip_attachment = null;
        if ($response->body->addressesSet->item) {
            foreach ($response->body->addressesSet->item as $item) {
                if ($item->publicIp == $nfs_eip) {
                    $nfs_eip_attachment = $item;
                    break;
                }
            }
        }
        
        if (!isset($item)) {
            throw new Exception('Unable to locate NAS Elastic IP in the EIP listing; cannot start', Exceptions\FAILED_PRESTART);
        } elseif (!isset($item->instanceId)) {
            throw new Exception('The NAS Elastic IP was found but is not currently attached to an instance; cannot start', Exceptions\FAILED_PRESTART);
        }
        
        $response = $ec2->describe_instances(array('InstanceId' => (string)$nfs_eip_attachment->instanceId));
        if ($response->body->reservationSet->item->instancesSet->item->dnsName) {
            $nfs_server_hostname = (string)$response->body->reservationSet->item->instancesSet->item->dnsName;
        } else {
            throw new Exception('Unable to locate the DNS name for the NAS Elastic IP; cannot start', Exceptions\FAILED_PRESTART);
        }
        */
        // </Grab NAS config information>
        
        // <Mount NFS volume>
        if (!is_dir('/mnt/nas')) {
            if (!mkdir('/mnt/nas')) {
                throw new Exception('Mount point for NAS data is missing and cannot be created; cannot start', Exceptions\FAILED_PRESTART);
            }
        }
        
        $cmd = '/bin/mount ' . escapeshellarg($nfs_eip_hostname) . ':/mnt/nas/export /mnt/nas';
        exec($cmd, $mount_output, $mount_return_status);
        
        if ($mount_return_status !== 0) {
            throw new Exception('Attempted to mount NAS data but failed for an unknown reason', Exceptions\FAILED_PRESTART);
        }
        // </Mount NFS volume>
        
        // <Align local configuration>
        copy('/mnt/nas/etc/php.ini', '/etc/php.ini');
        
        // APC removed
        // copy('/mnt/nas/etc/php.d/apc.ini', '/etc/php.d/apc.ini');
        
        `/bin/rpm -e php-pecl-apc`;
        `/usr/bin/yum install -y php-xcache xcache-admin`;
        copy('/mnt/nas/etc/php.d/xcache.ini', '/etc/php.d/xcache.ini');
        
        if (!is_link('/var/lib/php/session')) {
            if (file_exists('/var/lib/php/session')) {
                rename('/var/lib/php/session', '/var/lib/php/session.old');
            }
            symlink('/mnt/nas/var/lib/php/session', '/var/lib/php/session');
        }
        // </Align local configuration>
    }
    
    public function startService() {
        // <Start Apache>
        $cmd = '/usr/sbin/apachectl start';
        exec($cmd, $apachectl_output, $apachectl_return_status);
        if ($apachectl_return_status !== 0) {
            throw new Exception('Attempted to start Apache but failed for an unknown reason', Exceptions\FAILED_START);
        }
        // </Start Apache>
    }
    
    public function postStart() {
        // Health check, if good then open iptables/add instance to ELB
        
        // <Grab prerequisite data to finalize startup>
        $config = Core::loadConfiguration();
        if (isset($config['was']['elb_name'])) {
            $elb_name = $config['was']['elb_name'];
        } else {
            throw new Exception('Unable to locate WAS ELB identifier; cannot attach to pool', Exceptions\FAILED_POSTSTART);
        }
        
        $my_instanceId = file_get_contents('http://169.254.169.254/latest/meta-data/instance-id');
        if (!$my_instanceId) {
            throw new Exception('Unable to determine this instance\'s InstanceID using the meta-data service', Exceptions\FAILED_POSTSTART);
        }
        // </Grab prerequisite data to finalize startup>
        
        // <Insert iptables port allowances>
        $cmd = '/sbin/iptables -I INPUT -p tcp --match multiport --dports 80,443 -m state --state NEW,ESTABLISHED -j ACCEPT';
        exec($cmd, $iptables_output, $iptables_return_status);
        if ($iptables_return_status !== 0) {
            throw new Exception('Unable to insert appropriate iptables port allowances; cannot make self visible to ELB', Exceptions\FAILED_POSTSTART);
        }
        // </Insert iptables port allowances>
        
        // <Register instance with the appropriate ELB>
        $elb = new \AmazonELB(array('credentials' => 'was'));
        $elb->set_region(\AmazonELB::REGION_US_W2);
        $response = $elb->register_instances_with_load_balancer($elb_name, array(
            array('InstanceId' => $my_instanceId),
        ));
        
        if (!$response->isOK()) {
            throw new Exception('Failed to ensure that this WAS instance is registered with the appropriate ELB', Exceptions\FAILED_POSTSTART);
        }
        // </Register instance with the appropriate ELB>
        
        // <Set this instance name>
        $ec2 = new \AmazonEC2(array('credentials' => 'was'));
        $ec2->set_region(\AmazonEC2::REGION_US_W2);
        $response = $ec2->create_tags($my_instanceId, array(
            array('Key' => 'Name', 'Value' => 'Endor WAS (Autoscaled)'),
        ));

        if (!$response->isOK()) {
            throw new Exception('Failed to ensure that this WAS instance is tagged with the correct name', Exceptions\FAILED_POSTSTART);
        }
        // </Set this instance name>
        
        // <Send SNS notification>
        // @todo
        // </Send SNS notification>
        
        // <Record when we started>
        $this->setStateVariable('last_start', time());
        // <Record when we started>
    }
    
    public function preStop() {
        // <Attempt to deregister instance from ELB>
        $config = Core::loadConfiguration();
        if (isset($config['was']['elb_name'])) {
            $elb_name = $config['was']['elb_name'];
            $my_instanceId = file_get_contents('http://169.254.169.254/latest/meta-data/instance-id');
            if ($my_instanceId) {
                $elb = new \AmazonELB(array('credentials' => 'was'));
                $elb->set_region(\AmazonELB::REGION_US_W2);
                $response = $elb->deregister_instances_from_load_balancer($elb_name, array(
                    array('InstanceId' => $my_instanceId),
                ));
            }
        }
        // </Attempt to deregister instance from ELB>
        
        // <Attempt to close off iptables port allowances>
        $cmd = '/sbin/iptables -vnL INPUT --line-numbers |grep 80,443 | /bin/awk \' { print $1 } \' ';
        $rule_index = intval(`$cmd`);
        if ($rule_index > 0) {
            $cmd = '/sbin/iptables -D INPUT ' . escapeshellarg($rule_index);
            exec($cmd, $iptables_output, $iptables_return_status);
        }
        // </Attempt to close off iptables port allowances>
        
        // throw new Exception('Failure in preStop routine', Exceptions\FAILED_PRESTOP);
    }
    
    public function stopService() {
        // <Stop Apache>
        $cmd = '/usr/sbin/apachectl stop';
        exec($cmd, $apachectl_output, $apachectl_return_status);
        // </Stop Apache>
        
        // throw new Exception('Failure in stopService routine', Exceptions\FAILED_STOP);
    }
    
    private function restartApache() {
        // <Restart Apache>
        // This is a little flaky, let's not do it until we've shored it up
        // $cmd = '/usr/sbin/apachectl graceful';
        // exec($cmd, $apachectl_output, $apachectl_return_status);
        // </Restart Apache>
        
        // throw new Exception('Failure in stopService routine', Exceptions\FAILED_STOP);
    }
    
    public function postStop() {
        // <Unmount NFS volume>
        $cmd = '/bin/umount -l /mnt/nas';
        exec($cmd, $mount_output, $mount_return_status);
        // </Unmount NFS volume>
        
        // throw new Exception('Failure in postStop routine', Exceptions\FAILED_POSTSTOP);
    }
    
    public function restartService() {
        $this->preStop();
        $this->stopService();
        $this->startService();
        $this->postStart();
    }
    
    public function monitorSQS() {
        // No known reasons for us to monitor SQS right now
    }
    
    public function checkConfigurationUpdates() {
        // <Check for configuration updates since last restart>
        $mtime = $this->getLatestMtime('/etc/httpd/.');
        $last_start = $this->getStateVariable('last_start');
        
        if ($mtime > $last_start) {
            $this->setStateVariable('last_start', time());
            
            // Staggered restart; try to prevent all WAS instances from simultaneously restarting themselves
            $sleeptime = rand(2,90);
            echo "Going to restart WAS services; sleeping $sleeptime seconds first.\n";
            sleep($sleeptime);
            
            $this->restartApache();
        }
        // </Check for configuration updates since last restart>
    }
    
    private function getLatestMtime($dir) {
        $mtime = 0;
        
        $d = dir($dir);
        while ($file = $d->read()) {
            if ($file == '.' || $file == '..')  continue;
            $realfile = $dir . '/' . $file;
            
            if (is_dir($realfile)) {
                $file_mtime = $this->getLatestMtime($realfile);
            } elseif (is_file($realfile)) {
                $file_mtime = filemtime($realfile);
            }
            
            if ($file_mtime > $mtime) {
                $mtime = $file_mtime;
            }
        }
        
        return $mtime;
    }
}
