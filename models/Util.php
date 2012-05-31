<?php

class Util
{
    /**
     * Parse Configuration File.
     *
     * @return array config.
     */
    public static function config()
    {
        return include(preg_replace("@/$@", "", dirname(dirname(__FILE__))) . "/config.php");
    }


    /**
     * Send HTTP Post Request.
     *
     * @param string $url             Server to Post Data to.
     * @param string $data            Data to Post to Server.
     * @param string $optionalHeaders HTTP Header String
     *
     * @return string HTTP Post Result.
     */
    public static function postRequest($url, $data, $optionalHeaders=null)
    {
        $params = array('http' => array(
            'method'  => 'POST',
            'content' => $data,
            'header'  => $optionalHeaders,
        ));

        $ctx = stream_context_create($params);
        $fp = fopen($url, 'rb', false, $ctx);
        if (!$fp)
            throw new Exception("Problem with $url");

        $response = stream_get_contents($fp);
        if ($response === false)
            throw new Exception("Problem reading data from $url");

        return $response;
    }

}

