<?php
/**
 * Quickbooks & Nexternal Integration Utility Functions.
 *
 * PHP version 5.3
 *
 * @author   Brandon Lyon <brandon@lyonaround.com>
 * @version  GIT:<git_id>
 */


/**
 * Read contents of next Cache file for Type.
 * Cache file returned will be purged and subsequent cache files will be
 * cycled down.
 *
 * @param string $type Type of Cache File to read.
 *
 * @return string Contents of Cache File.
 */
function readCache($type)
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

    return $data;
}


/**
 * Write data to Cache File.
 *
 * @param string $type Type of Cache File to write.
 * @param string $data Data to write to Cache File.
 *
 * @return null
 */
function writeCache($type, $data)
{
    $delta          = 1;
    $baseFilename   = CACHE_DIR . $type;
    $filename       = $baseFilename . CACHE_EXT;
    while (file_exists($filename)) {
        $filename = $baseFilename . '__' . $delta . CACHE_EXT;
        $delta++;
    }
    if ($fh = fopen($filename, 'w')) {
        if (!fwrite($fh, $data)) {
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
function deleteCache($type)
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
 * @param string $duration day, week, month, year
 *
 * @return integer
 */
function convertTime($duration)
{
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
function validateTime($time)
{
    return (in_array($time, array('day','week','month','year')));
}


/**
 * Parse File's from CLI Arguments.
 *
 * @param array &$args Valid Command Line Arguments.
 *
 * @return boolean TRUE on success, or FALSE on failure.
 */
function parseArgs(&$args)
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
function showHelp()
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
