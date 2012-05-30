<?php
/**
 * Quickbooks & Nexternal Integration Process Functions.
 *
 * PHP version 5.3
 *
 * @author   Brandon Lyon <brandon@lyonaround.com>
 * @version  GIT:<git_id>
 */

require_once dirname(__FILE__) . '/Util.php';
require_once dirname(__FILE__) . '/Nexternal.php';
//require_once dirname(__FILE__) . '/Quickbooks.php';


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
    // Connect to Nexternal.
    $nexternal = nexternalAuth();

    // Check for failed auth.
    if (!$nexternal) {
        return false;
    }

    // Download Customers from Nexternal.
    if ($customers) {
    }

    // Download Orders from Nexternal.
    if ($orders) {
        $nxOrders = nexternalGetOrders($nexternal, $from, $to);

        // Check for Cache before sending orders to QB.
        if (file_exists(CACHE_DIR . NEXTERNAL_ORDER_CACHE . CACHE_EXT)) {
            // Save orders to cache and process cache.
            writeCache(NEXTERNAL_ORDER_CACHE, serialize($orders));
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
