<?php

namespace EwokOS;

class Metrics {
    private $cw = null;
    
    public function __construct() {
        $this->cw = new \AmazonCloudWatch();
        $this->cw->set_region(\AmazonCloudWatch::REGION_US_W2);
    }
    
    public function reportMetrics() {
        // $this->reportIntertechAPI();
        $this->reportPulse();
        $this->reportF5();
        $this->reportAtlassian();
    }
    
    private function reportIntertechAPI() {
        $ch = curl_init('https://services.randfstaging.com/SecurityService/Login');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $params = array(
            'culture' => 'en-US',
            'userName' => 'test',
            'password' => '123',
            'type' => 'api',
        );
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        $start = microtime(true);
        $resp = curl_exec($ch);
        $time = round(microtime(true) - $start, 2);
        curl_close($ch);
        
        if (json_decode($resp)) {
            $resp = true;
        } else {
            $resp = false;
        }
        
        if ($resp === false) {
            $this->cw->put_metric_data('Endor', array(
                array(
                    'MetricName' => 'IntertechAPI_ResponseReceived',
                    'Value' => 0,
                    'Unit' => 'Count',
                ),
            ));
        } else {
            $this->cw->put_metric_data('Endor', array(
                array(
                    'MetricName' => 'IntertechAPI_ResponseReceived',
                    'Value' => 1,
                    'Unit' => 'Count',
                ),
            ));
            $this->cw->put_metric_data('Endor', array(
                array(
                    'MetricName' => 'IntertechAPI_ResponseTime',
                    'Value' => $time,
                    'Unit' => 'Seconds',
                ),
            ));
        }
    }
    
    private function reportF5() {
        $ch = curl_init('http://www.rodanandfields.com/rfconnection/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $start = microtime(true);
        $resp = curl_exec($ch);
        $time = round(microtime(true) - $start, 2);
        curl_close($ch);
        
        if ($resp === false) {
            $this->cw->put_metric_data('Endor', array(
                array(
                    'MetricName' => 'RackspaceF5_ResponseReceived',
                    'Value' => 0,
                    'Unit' => 'Count',
                ),
            ));
        } else {
            $this->cw->put_metric_data('Endor', array(
                array(
                    'MetricName' => 'RackspaceF5_ResponseReceived',
                    'Value' => 1,
                    'Unit' => 'Count',
                ),
            ));
            $this->cw->put_metric_data('Endor', array(
                array(
                    'MetricName' => 'RackspaceF5_ResponseTime',
                    'Value' => $time,
                    'Unit' => 'Seconds',
                ),
            ));
        }
    }
    
    private function reportPulse() {
        $token = self::validatePulseUserPassword('doe', 'doe');
        if ($token === false) {
            $ResponseReceived = 0;
        } else {
            $ResponseReceived = 1;
        }
        $this->cw->put_metric_data('Endor', array(
            array(
                'MetricName' => 'PulseMobileAuthentication_ResponseReceived',
                'Value' => $ResponseReceived,
                'Unit' => 'Count',
            ),
        ));
    }
    
    private function reportAtlassian() {
        $ch = curl_init('https://rodanandfields.atlassian.net/svn/LAMP');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $resp = curl_exec($ch);
        curl_close($ch);
        
        if ($resp === false) {
            $this->cw->put_metric_data('Endor', array(
                array(
                    'MetricName' => 'Atlassian_ResponseReceived',
                    'Value' => 0,
                    'Unit' => 'Count',
                ),
            ));
        } else {
            $this->cw->put_metric_data('Endor', array(
                array(
                    'MetricName' => 'Atlassian_ResponseReceived',
                    'Value' => 1,
                    'Unit' => 'Count',
                ),
            ));
        }
    }
    
    private static function validatePulseUserPassword($username, $password)
    {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_POST => 1,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_URL => 'https://mobile.rodanandfields.com/Pulse2/Security/AuthConsultant',
                CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
                CURLOPT_USERAGENT => 'Pulse 1.0.1 (iPhone Simulator; iPhone OS 6.0; en_US)',

                /* debuggery (w/ Charles)
                CURLOPT_PROXY => 'http://localhost:8888',
                CURLOPT_SSL_VERIFYPEER => 0,
                //*/
                ));

            $postData = array(
                'version' => '1.0.1',
                'username' => $username,
                'password' => $password,
                );
            $postData = json_encode($postData);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

            $resp = curl_exec($ch);
            if (strlen($resp) < 1) {
                throw new \Exception('No authentication response received');
            }

            $respArray = json_decode($resp, true);
            if ($respArray === null) {
                throw new \Exception('Authentication JSON response was invalid');
            }

            if (isset($respArray['authResult']['Token'])) {
                return $respArray['authResult']['Token'];
            } else {
                throw new \Exception('Authentication JSON response was valid but contained no token or an unexpected data structure');
            }
        } catch (\Exception $e) {
            return false;
        }

        return false;
    }
}
