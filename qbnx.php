#!/usr/bin/php
<?php
/**
 * @file        qbnx.php
 * @author      Brandon Lyon
 * @description
 * Collect Customer and Invoice Data from Nexternal or Quickbooks and pass
 * it to the other system.
 *
 * @arguments
 * Arguments:
 *   -h     Help Menu
 *   -q     Push data to Quickbooks from Nexternal
 *   -n     Push data to Nexternal from Quickbooks
 *   -t     Define duration of Time to sync:
 *           day, week, month, or time in seconds.
 *
 * Example: ./qbnx.php -n -t week
 */
require_once('lib/Log.php');
require_once('lib/Nexternal.php');
//require_once('lib/Quickbooks.php');
DEFINE('START_TIME',                time());
DEFINE('MEMORY_CAP',                104857600); // 100 MB
DEFINE('CACHE_DIR',                 dirname(__FILE__) . "/cache/");
DEFINE('CACHE_EXT',                 '.cache');
DEFINE('NEXTERNAL_ORDER_CACHE',     'NexternalOrders');
DEFINE('NEXTERNAL_CUSTOMER_CACHE',  'NexternalCustomers');
DEFINE('QUICKBOOKS_ORDER_CACHE',    'QuickbooksOrders');
DEFINE('QUICKBOOKS_CUSTOMER_CACHE', 'QuickbooksCustomers');

// Define Arguments.
$args = array(
    'h' => false,
    'q' => false,
    'n' => false,
    't' => 'week',
);

// Initalize Log.
$log = Log::getInstance();

// Parse user entered Arguments.
parseArgs($args);

// Check for help.
if ((!$args['q'] && !$args['n']) || $args['h']) {
    showHelp();
    exit();
}
// Validate Time.
if (!in_array($args['t'], array('day','week','month','year'))) {
    showHelp();
    exit();
}


// Check for Quickbooks Argument.
if ($args['q']) {

    // Connect to Nexternal.
    $nexternal = new Nexternal;
    nexternalAuth($nexternal);

    // Download Orders from Nexternal.
    $orders = nexternalGetOrders($nexternal, $args['t']);

    // Check for Cache before sending orders to QB.
    if (file_exists(CACHE_DIR . NEXTERNAL_ORDER_CACHE . CACHE_EXT)) {
        // Save orders to cache and process cache.
        writeCache(NEXTERNAL_ORDER_CACHE, serialize($orders));
        while (NULL !== ($orders = readCache(NEXTERNAL_ORDER_CACHE))) {
            print "Send orders to QB from Cache\n";
            // @TODO: Send to QB.
        }
    } else {
        // @TODO: Send to QB.
        print "Send orders to QB\n";
    }

}



/**
 * Authenticate with Nexternal Server.
 *
 * @param Nexternal $nx Nexternal Object Reference.
 */
function nexternalAuth(Nexternal $nx) {
    global $log;
    $log->write(Log::INFO, "Authenticating");
    $dom = $nx->sendAuthentication();
    if ($nx->processAuthenticationResponse($dom)) {
        $log->write(Log::INFO, "Sending Verification");
        $dom = $nx->sendVerification();
        if ($nx->processVerificationResponse($dom)) {
            $log->write(Log::INFO, "Authenticated!");
            return;
        }
    }
    $log->write(Log::CRIT, "Authentication Failed!");
    exit();
}


/**
 * Download Orders from Nexternal.
 *
 * @param Nexternal $nx       Nexternal Object Reference.
 * @param string    $duration (day, week, month, year)
 *
 * @return array of Orders.
 */
function nexternalGetOrders(Nexternal $nx, $duration) {
    // Query "paid" Nexternal Orders.
    $page      = 0;
    $morePages = true;
    $orders    = array();
    while ($morePages) {
        $page++;
        $response = $nx->processOrderQueryResponse(
            $nx->orderQuery(
                START_TIME - convertTime($duration),
                START_TIME,
                Nexternal::BILLSTAT_PAID,
                $page
            )
        );

        // Add Order(s) to Array.
        foreach ($response['orders'] as $order) {
            $orders[] = $order;
        }

        // set additional pages flag.
        $morePages = $response['morePages'];

        // reset response.
        unset($response);

        // write Orders to File if we've reached our cache cap.
        if (MEMORY_CAP <= memory_get_usage()) {
            writeCache(NEXTERNAL_ORDER_CACHE, serialize($orders));
            $orders = array();
        }
    }

    return $orders;
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
function readCache($type) {
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
 */
function writeCache($type, $data) {
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
 */
function deleteCache($type) {
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
 * @param string $duration
 *
 * @return integer
 */
function convertTime($duration) {
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
 * Parse File's from CLI Arguments.
 *
 * @return TRUE on success, or FALSE on failure.
 */
function parseArgs(&$args) {
    global $log, $argv;

    foreach ($argv as $key => $arg) {
        if ($key == 0) { continue; }
        if (preg_match("/^\-(.*$)/", $arg, $match)) {
            if (array_key_exists(1+$key, $argv)
                && !preg_match("/^\-(.$)/", $argv[1+$key])) {
                if (!array_key_exists($match[1], $args)) {
                    $log->write(Log::CRIT, sprintf("Invalid Argument: %s", $match[1]));
                    continue;
                }
                $args[$match[1]] = $argv[1+$key];
            } else {
                if (!array_key_exists($match[1], $args)) {
                    $log->write(Log::CRIT, sprintf("Invalid Argument: %s", $match[1]));
                    continue;
                }
                $args[$match[1]] = true;
            }
        }
    }
}


/**
 * Print "Usage" Information from Header Comment in this file.
 */
function showHelp() {
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

