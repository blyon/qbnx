<?php
/**
 * Quickbooks & Nexternal Integration Utility Functions.
 *
 * PHP version 5.3
 *
 * @author  Brandon Lyon <brandon@lyonaround.com>
 * @version GIT:<git_id>
 */

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


    /**
     * Read contents of next Cache file for Type.
     * Cache file returned will be purged and subsequent cache files will be
     * cycled down.
     *
     * @param string $type Type of Cache File to read.
     *
     * @return string Contents of Cache File.
     */
    public static function readCache($type)
    {
        $data           = null;
        $delta          = 1;
        $baseFilename   = CACHE_DIR . $type;
        $filename       = $baseFilename . CACHE_EXT;
        if (file_exists($filename)) {
            $data = file_get_contents($filename);
        }
        unlink($filename);
        $filename = $baseFilename . '__' . $delta . CACHE_EXT;
        while (file_exists($filename)) {
            $newFilename = (1 == $delta)
                ? $baseFilename . CACHE_EXT
                : $baseFilename . '__' . ($delta-1) . CACHE_EXT;
            rename($filename, $newFilename);
            $delta++;
            $filename = $baseFilename . '__' . $delta . CACHE_EXT;
        }

        return is_null($data) ? $data : unserialize($data);
    }


    /**
     * Write data to Cache File.
     *
     * @param string $type Type of Cache File to write.
     * @param string $data Data to write to Cache File.
     *
     * @return null
     */
    public static function writeCache($type, $data)
    {
        $delta          = 1;
        $baseFilename   = CACHE_DIR . $type;
        $filename       = $baseFilename . CACHE_EXT;
        while (file_exists($filename)) {
            $filename = $baseFilename . '__' . $delta . CACHE_EXT;
            $delta++;
        }
        if (($fh = fopen($filename, 'w'))) {
            if (!fwrite($fh, serialize($data))) {
                throw new Exception("Failed to write to Cache File: " . $file);
            }
            fclose($fh);
        } else {
            throw new Exception("Failed to open Cache File for Writing: " . $file);
        }
    }


    /**
     * Delete ALL cache files for given type.
     *
     * @param string $type Type of cache file to purge.
     *
     * @return null
     */
    public static function deleteCache($type)
    {
        $delta          = 1;
        $baseFilename   = CACHE_DIR . $type;
        $filename       = $baseFilename . CACHE_EXT;
        while (file_exists($filename)) {
            unlink($filename);
            $filename = $baseFilename . '__' . $delta . CACHE_EXT;
        }
    }


    /**
     * Convert Time Argument to seconds.
     *
     * @param string $duration 'day', 'week', 'month', 'year', or numeric string
     *
     * @return integer
     */
    public static function convertTime($duration)
    {
        if (preg_match("/^[0-9]+$/", $duration)) {
            return (int) $duration;
        }
        switch ($duration) {
            case 'day':
                return (60 * 60 * 24);
            case 'week':
                return (60 * 60 * 24 * 7);
            case 'month':
                return (60 * 60 * 24 * 30);
            case 'year':
                return (60 * 60 * 24 * 365);
            default:
                return 0;
        }
    }


    /**
     * Validate Time String.
     *
     * @param string $time Time string to Validate.
     *
     * @return boolean
     */
    public static function validateTime($time)
    {
        return (preg_match("/^[0-9]+$/", $time) || in_array($time, array('day','week','month','year')));
    }


    /**
     * Parse File's from CLI Arguments.
     *
     * @param array &$args Valid Command Line Arguments.
     *
     * @return boolean TRUE on success, or FALSE on failure.
     */
    public static function parseArgs(&$args)
    {
        global $argv;
        $log = Log::getInstance();

        foreach ($argv as $key => $arg) {
            if ($key == 0) {
                continue;
            }
            if (preg_match("/^\-(.*$)/", $arg, $match)) {
                if (array_key_exists(1+$key, $argv)
                    && !preg_match("/^\-(.$)/", $argv[1+$key])
                ) {
                    if (!array_key_exists($match[1], $args)) {
                        $log->write(
                            Log::CRIT,
                            sprintf("Invalid Argument: %s", $match[1])
                        );
                        continue;
                    }
                    $args[$match[1]] = $argv[1+$key];
                } else {
                    if (!array_key_exists($match[1], $args)) {
                        $log->write(
                            Log::CRIT,
                            sprintf("Invalid Argument: %s", $match[1])
                        );
                        continue;
                    }
                    $args[$match[1]] = true;
                }
            }
        }
    }


    /**
     * Print "Usage" Information from Header Comment in this file.
     *
     * @return null
     */
    public static function showHelp()
    {
        $this_file = file($_SERVER['PHP_SELF']);
        $token = false;
        foreach ($this_file as $line) {
            if (preg_match('/^\s\*\sArguments/', $line)) {
                $token = true;
                print "\n";
            }
            if ($token) {
                if (preg_match('/^\s\*\//', $line)) {
                    break;
                }
                print preg_replace('/^\s\*/', '', $line);
            }
        }
    }


    public static function downloadUpdate()
    {
        $file = "https://github.com/blyon/qbnx/zipball/master";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 0);
        curl_setopt($ch, CURLOPT_URL, $file);

        $response = curl_exec($ch);

        curl_close($ch);

        $fh = fopen("update.zip","w");
        fwrite($fh, $response);
        fclose($fh);
        system("unzip update.zip");
    }
}
