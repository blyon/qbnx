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
require_once dirname(__FILE__) . '/../controllers/NexternalController.php';
require_once dirname(__FILE__) . '/../controllers/QuickbooksController.php';


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
    //$quickbooks = new QuickbooksController();
    //$result = $quickbooks->querySalesReceipt('N124827');
    //print_r($result);
    //return;

    // Check for failed auth.
    //if (!$nexternal || !$quickbooks) {
        //return false;
    //}

    // Download Customers from Nexternal.
    if ($customers) {
        $totalCustomers = 0;
        $nxCustomers    = $nexternal->getCustomers($from, $to);
        $totalCustomers += count($nxCustomers);

        // Check for Cache before sending customers to QB.
        if (file_exists(CACHE_DIR . NEXTERNAL_CUSTOMER_CACHE . CACHE_EXT)) {
            // Save orders to cache and process cache.
            Util::writeCache(NEXTERNAL_CUSTOMER_CACHE, serialize($nxCustomers));
            while (null !== ($nxCustomers = Util::readCache(NEXTERNAL_CUSTOMER_CACHE))) {
                $totalCustomers += count($nxCustomers);
                print "Send customers to QB from Cache\n";
                // @TODO: Send to QB.
            }
        } else {
            // @TODO: Send to QB.
            print "Send customers to QB\n";
        }
        printf("Total Customers Sent to QB: %d\n", $totalCustomers);
    }

    // Download Orders from Nexternal.
    if ($orders) {
        $totalOrders = 0;
        $nxOrders    = $nexternal->getOrders($from, $to);
        $totalOrders += count($nxOrders);

        // Check for Cache before sending orders to QB.
        if (file_exists(CACHE_DIR . NEXTERNAL_ORDER_CACHE . CACHE_EXT)) {
            // Save orders to cache and process cache.
            Util::writeCache(NEXTERNAL_ORDER_CACHE, serialize($nxOrders));
            while (null !== ($nxOrders = Util::readCache(NEXTERNAL_ORDER_CACHE))) {
                $totalOrders += count($nxOrders);
                print_r(end($nxOrders));
                print "Send orders to QB from Cache\n";
                // @TODO: Send to QB.
            }
        } else {
            // @TODO: Send to QB.
            print "Send orders to QB\n";
        }
        printf("Total Orders Sent to QB: %d\n", $totalOrders);
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
