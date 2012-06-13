<?php
/**
 * Quickbooks & Nexternal Integration Process Functions.
 *
 * PHP version 5.3
 *
 * @author  Brandon Lyon <brandon@lyonaround.com>
 * @version GIT:<git_id>
 */

require_once dirname(__FILE__) . '/Util.php';
require_once dirname(__FILE__) . '/NexternalController.php';
require_once dirname(__FILE__) . '/QuickbooksController.php';


/**
 * Push Data modified in the given date range from Nexternal to Quickbooks.
 *
 * @param integer $from      Beginning Date Range
 * @param integer $to        End Date Range
 * @param boolean $orders    Push Order Data?
 * @param boolean $customers Push Customer Data?
 *
 * @return boolean
 */
function pushNexternalToQuickbooks($from, $to, $orders=true, $customers=true)
{
    $log = Log::getInstance();

    // Connect to Nexternal.
    $nexternal = new NexternalController();

    // Authenticate with Nexternal.
    if (!$nexternal->authenticate()) {
        throw new Exception("Nexternal Authentication Failed.");
    }

    // Connect to Quickbooks.
    $quickbooks = new QuickbooksController();
    //$result = $quickbooks->querySalesReceipt('N124827');
    //print_r($result);
    //return;

    // Check for failed auth.
    //if (!$nexternal || !$quickbooks) {
        //return false;
    //}

    // Download Customers from Nexternal.
    if ($customers) {
        $nxCustomers = $nexternal->getCustomers($from, $to);

        // Check for Cache before sending customers to QB.
        if (file_exists(CACHE_DIR . NEXTERNAL_CUSTOMER_CACHE . CACHE_EXT)) {
            // Save orders to cache and process cache.
            writeCache(NEXTERNAL_CUSTOMER_CACHE, serialize($nxCustomers));
            while (null !== ($nxCustomers = readCache(NEXTERNAL_CUSTOMER_CACHE))) {
                print "Send customers to QB from Cache\n";
                // @TODO: Send to QB.
            }
        } else {
            // @TODO: Send to QB.
            print "Send customers to QB\n";
        }
    }

    // Download Orders from Nexternal.
    if ($orders) {
        $nxOrders = $nexternal->getOrders($from, $to);

        // Check for Cache before sending orders to QB.
        if (file_exists(CACHE_DIR . NEXTERNAL_ORDER_CACHE . CACHE_EXT)) {
            // Save orders to cache and process cache.
            writeCache(NEXTERNAL_ORDER_CACHE, serialize($nxOrders));
            while (null !== ($nxOrders = readCache(NEXTERNAL_ORDER_CACHE))) {
                print "Send orders to QB from Cache\n";
                // @TODO: Send to QB.
            }
        } else {
            // @TODO: Send to QB.
            print "Send orders to QB\n";
        }
    }
}


/**
 * Push Data modified in the given date range from Quickbooks to Nexternal.
 *
 * @param integer $from      Beginning Date Range
 * @param integer $to        End Date Range
 * @param boolean $orders    Push Order Data?
 * @param boolean $customers Push Customer Data?
 *
 * @return boolean
 */
function pushQuickbooksToNexternal($from, $to, $orders=true, $customers=true)
{

}
