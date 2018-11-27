<?php
class com_gripp_API{

    /*
     * Version 3.0
     * Free to use in any way.
     * Created by Gripp.com B.V.
     */

    private $apitoken;
    private $url;
    private $id = 1;
    private $batchmode = false;
    private $requests = array();
    private $reponseHeaders = array();

    public function __construct($apitoken, $url = 'https://api.gripp.com/public/api3.php'){
        set_time_limit(0);
        if (!$apitoken){
            throw new \Exception('Api token is required');
        }
        if (!$url){
            throw new \Exception('Url is required');
        }

        if (!strstr($url, 'api3.php')){
            throw new \Exception('This API connector is suitable for the Gripp API v3 only.');
        }

        $this->apitoken = $apitoken;
        $this->url = $url;
    }

    public function setBatchmode($b){
        $this->batchmode = $b;
    }

    public function getBatchmode(){
        return $this->batchmode;
    }

    public function handleResponseErrors($responses){
        $messages = array();

        foreach($responses as $response){
            if (array_key_exists('error', $response) && !empty($response['error'])){
                if (array_key_exists('error_code', $response)){
                    switch($response['error_code']){
                        default:
                            $messages[] = $response['error'];
                            break;
                    }
                }
                else {
                    $messages[] = $response['error'];
                }
            }
            else{
                unset($this->requests[$response['id']]);
            }
        }

        if (count($messages) > 0){
            throw new \Exception(implode("\n", $messages));
        }

        return $responses;
    }

    function getRawPost(){
        $post = array();

        foreach($this->requests as $r){
            $post[] = array(
                'method' => $r['class'].'.'.$r['method'],
                'params' => $r['params'],
                'id' => $r['id']
            );
        }

        return $post;
    }

    function run(){
        //call
        $post = $this->getRawPost();
        if (count($post) > 0){
            $post_string = json_encode($post);
            $result = $this->send($post_string);
            $result_decoded = json_decode($result, true);

            return $this->handleResponseErrors($result_decoded);
        }
        else{
            return null;
        }
    }

    public function __call($fullmethod, $params){
        list($class, $method) = explode("_", $fullmethod);
        $id = $this->id++;

        $this->requests[$id] = array(
            'class' => $class,
            'method' => $method,
            'params' => $params,
            'id' => $id
        );
        if (!$this->batchmode){
            return $this->run();
        }
    }

    private function send($post_string) {
        $url =  $this->url;
        $that = $this;
        $that->reponseHeaders = array();

        $options = array(
            CURLOPT_VERBOSE => false,
            CURLOPT_POST => 1,
            CURLOPT_HEADER => 0,
            CURLOPT_URL => $url,
            CURLOPT_FRESH_CONNECT => 1,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FORBID_REUSE => 1,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POSTFIELDS => $post_string,
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer '.$this->apitoken
            ),
            CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headers, $that){
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2) // ignore invalid headers
                    return $len;

                $name = strtolower(trim($header[0]));
                if (!array_key_exists($name, $that->reponseHeaders))
                    $that->reponseHeaders[$name] = [trim($header[1])];
                else
                    $that->reponseHeaders[$name][] = trim($header[1]);

                return $len;
            }
        );
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $output = trim(curl_exec($ch));

        if ($output == ''){
            throw new \Exception('Got no response from API call: '.curl_error($ch));
        }

        $httpstatuscode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        switch($httpstatuscode){
            case 503:  //Service Unavailable: thrown by the throttling mechanism (either Nginx or Php)
                //Refire the request after Retry-After seconds.
                if (array_key_exists('retry-after', $this->reponseHeaders)) {
                    usleep($this->reponseHeaders['retry-after'][0]*1000000);
                    return $this->send($post_string);
                }
                else{
                    throw new \Exception('Received HTTP status code 503 without Retry-After header. Cannot automatically resend the request.');
                }

                break;
            case 429:{ //Too many requests: thrown by the API to inform you that your API Request Pack is depleted for this hour.
                throw new \Exception('Received HTTP status code: '.$httpstatuscode.'. Maximum number of request for this hour is reached. Please upgrade your API Request Packs.');
                break;
            }
            case 200:{ //OK
                return $output;
                break;
            }
            default:
                throw new \Exception('Received HTTP status code: '.$httpstatuscode);
                break;
        }
    }

}
?>