<?php
/**
 * Nexternal Controller Functions.
 *
 * PHP version 5.3
 *
 * @author   Brandon Lyon <brandon@lyonaround.com>
 * @version  GIT:<git_id>
 */

// Include necessary Models.
require_once dirname(__FILE__) . '/../models/Log.php';
require_once dirname(__FILE__) . '/../models/Nexternal.php';


/**
 * Authenticate with Nexternal Server.
 *
 * @return mixed Nexternal object or false.
 */
function nexternalAuth()
{
    $log = Log::getInstance();
    $nx  = new Nexternal;

    $log->write(Log::INFO, "Authenticating");
    $dom = $nx->sendAuthentication();
    if ($nx->processAuthenticationResponse($dom)) {
        $log->write(Log::INFO, "Sending Verification");
        $dom = $nx->sendVerification();
        if ($nx->processVerificationResponse($dom)) {
            $log->write(Log::INFO, "Authenticated!");
            return $nx;
        }
    }
    $log->write(Log::CRIT, "Authentication Failed!");
    return false;
}


/**
 *
 * @param Nexternal $nx   Nexternal Object Reference.
 * @param type      $from Beginning Time
 * @param type      $to   End Time
 *
 * @return array of Customers.
 */
function nexternalGetCustomers(Nexternal $nx, $from, $to)
{
    // Query Customers.
    $page      = 0;
    $morePages = true;
    $customers = array();
    while ($morePages) {
        $page++;
        $response = $nx->processCustomerQueryResponse(
            $nx->customerQuery($from, $to, $page)
        );

        // Add Customer(s) to Array.
        foreach ($response['customers'] as $customer) {
            $customers[] = $customer;
        }

        // set additional pages flag.
        $morePages = $response['morePages'];

        // reset response.
        unset($response);

        // write Customers to File if we've reached our cache cap.
        if (MEMORY_CAP <= memory_get_usage()) {
            writeCache(NEXTERNAL_CUSTOMER_CACHE, serialize($customers));
            $customers = array();
        }
    }

    return $customers;
}


/**
 * Download Orders from Nexternal.
 *
 * @param Nexternal $nx   Nexternal Object Reference.
 * @param integer   $from Beginning Time
 * @param integer   $to   End Time
 *
 * @return array of Orders.
 */
function nexternalGetOrders(Nexternal $nx, $from, $to)
{
    // Query "paid" Nexternal Orders.
    $page      = 0;
    $morePages = true;
    $orders    = array();
    while ($morePages) {
        $page++;
        $response = $nx->processOrderQueryResponse(
            $nx->orderQuery(
                $from,
                $to,
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
