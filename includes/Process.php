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
function pushNexternalToQuickbooks($from, $to, $orders=true)
{
    $totalOrders = array();
    $errors = array();

    // Connect to Nexternal.
    $nexternal = new NexternalController();

    // Authenticate with Nexternal.
    if (!$nexternal->authenticate()) {
        throw new Exception("Nexternal Authentication Failed.");
    }

    // Connect to Quickbooks.
    $quickbooks = new QuickbooksController();

    // Check for failed auth.
    if (!$nexternal || !$quickbooks) {
        return false;
    }

    // Download Orders from Nexternal.
    if ($orders) {
        $nxOrders    = $nexternal->getOrders($from, $to);
        $nxCustomers = array();

        // Check for Cache before sending orders to QB.
        if (file_exists(CACHE_DIR . NEXTERNAL_ORDER_CACHE . CACHE_EXT)) {
            // Save orders to cache and process cache.
            Util::writeCache(NEXTERNAL_ORDER_CACHE, serialize($nxOrders));
            while (null !== ($nxOrders = Util::readCache(NEXTERNAL_ORDER_CACHE))) {
                $result = _pushNexternalToQuickbooks($nxOrders, $nxCustomers, $nexternal, $quickbooks);
                $totalOrders = array_merge($totalOrders, $result['sentOrders']);
                $errors = array_merge($errors, $result['errors']);
            }
        } else {
            $result = _pushNexternalToQuickbooks($nxOrders, $nxCustomers, $nexternal, $quickbooks);
            $totalOrders = array_merge($totalOrders, $result['sentOrders']);
            $errors = array_merge($errors, $result['errors']);
        }
        printf("Total Orders Sent to QB: %d\n", count($totalOrders));
    }

    // Send Email
    $message = sprintf("Start Time: %s\nEnd Time: %s\n\nSync Orders From: %s To %s\n\nTotal Orders Sent to QB: %d\n\n\nOrder Numbers:\n%s", date('Y-m-d H:i:s', START_TIME), date('Y-m-d H:i:s'), date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to), count($totalOrders), implode("\n\t", $totalOrders));
    if (!empty($errors)) {
        Util::sendMail(MAIL_ERRORS, "Order ERROR Report for ToeSox", "The following Errors occurred while pushing Orders from Nexternal to Quickbooks.\n\n\n\n" . implode("\n\n", $errors));
    }
    Util::sendMail(MAIL_SUCCESS, "Order Report for ToeSox (NX->QB)", $message);
}


/**
 * Helper function for pushNexternalToQuickbooks to prevent code duplication.
 *
 * @param type $nxOrders
 * @param type $nxCustomers
 * @param type $nexternal
 * @param type $quickbooks
 */
function _pushNexternalToQuickbooks(&$nxOrders, &$nxCustomers, &$nexternal, &$quickbooks) {
    $log        = Log::getInstance();
    $errors     = array();
    $sentOrders = array();
    // Get Order Customers.
    foreach ($nxOrders as $nxOrder) {
        // Get Customer.
        if (!array_key_exists($nxOrder->customer, $nxCustomers)) {
            $nxCustomers[$nxOrder->customer] = $nexternal->getCustomer($nxOrder->customer);
        }
    }
    print "Send orders to QB from Cache\n";
    foreach ($nxOrders as $nxOrder) {
        $nx_customer = $nxCustomers[$nxOrder->customer];
        $order_check = $quickbooks->getSalesReceiptByOrderId($nxOrder->id);
        if (empty($order_check)) {

            // If the type is consumer use customer F
            // If not search first by quickbooks id if set, if not then search by email and name
            // If a customer is still not found then create it, if there was a error then dont send order
            // If a customer was found then lets send the order over

            // Attempt to lookup the Customer.
            $customer = ('consumer' == strtolower($nx_customer->type)
                ? $quickbooks->getCustomerbyName('f')
                : (!empty($nx_customer->quickbooksId)
                    ? $quickbooks->getCustomer($nx_customer->quickbooksId)
                    : $quickbooks->getCustomerByName($nx_customer->company
                    )
                )
            );
            // Create the Customer if Customer not found.
            if (!$customer) {
                if (!($customer = $quickbooks->createCustomer($nx_customer,$nxOrder))) {
                    $errors[] = sprintf("Could not create Customer[%s] for Order[%s] - %s", $nx_customer->type, $nxOrder->id, $quickbooks->last_error);
                    $log->write(Log::ERROR, end($errors));
                    continue;
                }
            }
            if (!($salesReceipt = $quickbooks->addSalesReceipt($nxOrder, $customer))) {
                $errors[] = sprintf("Could not create Order[%s] - %s", $nxOrder->id, $quickbooks->last_error);
                $log->write(Log::ERROR, end($errors));
                continue;
            }
            $sentOrders[] = $salesReceipt->id;
        } else {
            printf("Order %d Exists in QB\n", $nxOrder->id);
            continue;
        }
    }
    return array('sentOrders' => $sentOrders, 'errors' => $errors);
}


/**
 * Push Data modified in the given date range from Quickbooks to Nexternal.
 *
 * @param integer $from      Beginning Date Range
 * @param integer $to        End Date Range
 * @param boolean $orders    Push Order Data?
 *
 * @return boolean
 */
function pushQuickbooksToNexternal($from, $to, $orders=true)
{
    $totalOrders = array();
    $errors = array();

    // Connect to Nexternal.
    $nexternal = new NexternalController();

    // Authenticate with Nexternal.
    if (!$nexternal->authenticate()) {
        throw new Exception("Nexternal Authentication Failed.");
    }

    // Connect to Quickbooks.
    $quickbooks = new QuickbooksController();

    // Check for failed auth.
    if (!$nexternal || !$quickbooks) {
        return false;
    }

    if ($orders) {
        $qbOrders    = array_merge(
            $quickbooks->getSalesReceiptByDate($from, $to),
            $quickbooks->getInvoicesByDate($from, $to)
        );

        // Check for Cache before sending orders to QB.
        if (file_exists(CACHE_DIR . QUICKBOOKS_ORDER_CACHE . CACHE_EXT)) {
            // Save orders to cache and process cache.
            Util::writeCache(QUICKBOOKS_ORDER_CACHE, serialize($qbOrders));
            while (null !== ($cacheOrders = Util::readCache(QUICKBOOKS_ORDER_CACHE))) {
                $qbOrders = unserialize($cacheOrders);
                unset($cacheOrders);
                $result = _pushQuickbooksToNexternal($qbOrders, $nxCustomers, $nexternal, $quickbooks);
                $totalOrders = array_merge($totalOrders, $result['sentOrders']);
                $errors = array_merge($errors, $result['errors']);
            }
        } else {
            $result = _pushQuickbooksToNexternal($qbOrders, $nxCustomers, $nexternal, $quickbooks);
            $totalOrders = $result['sentOrders'];
            $errors = array_merge($errors, $result['errors']);
        }
        printf("Total Orders Sent to NX: %d\n", count($totalOrders));
    }

    // Send Email
    $message = sprintf("Start Time: %s\nEnd Time: %s\n\nSync Orders From: %s To %s\n\nTotal Orders Sent to NX: %d\n\n\nOrder Numbers:\n%s", date('Y-m-d H:i:s', START_TIME), date('Y-m-d H:i:s'), date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to), count($totalOrders), implode("\n\t", $totalOrders));

    if (!empty($errors)) {
        Util::sendMail(MAIL_ERRORS, "Order ERROR Report for ToeSox", "The following Errors occurred while pushing Orders from Quickbooks to Nexternal\n\n\n\n" . implode("\n\n", $errors));
    }
    Util::sendMail(MAIL_SUCCESS, "Order Report for ToeSox (QB->NX)", $message);
}


/**
 * Helper function for pushQuickbooksToNexternal to prevent code duplication.
 *
 * @param type $qbOrders
 * @param type $qbCustomers
 * @param type $nexternal
 * @param type $quickbooks
 * @return type
 */
function _pushQuickbooksToNexternal(&$qbOrders, &$qbCustomers, &$nexternal, &$quickbooks) {
    $log        = Log::getInstance();
    $errors     = array();
    $sentOrders = array();
    $qbCustomers= array();

    // Get Order Customers.
    foreach ($qbOrders as $k => $qbOrder) {
        // Skip if the order initiated from Nexternal.
        if (preg_match('/^N/', $qbOrder->id)) {
            unset($qbOrders[$k]);
            continue;
        }
        // Skip if Customer is black listed.
        if (in_array($qbOrder->customer, explode(" ", TOESOX_INTERNAL_CUSTOMERS))) {
            unset($qbOrders[$k]);
            continue;
        }
        // Get Customer.
        if (!array_key_exists($qbOrder->customer, $qbCustomers)) {
            $qbCustomers[$qbOrder->customer] = $quickbooks->getCustomer($qbOrder->customer);
        }
    }
    print "Send orders to Nexternal from Cache\n";
    foreach ($qbOrders as $qbOrder) {
        $qb_customer = $qbCustomers[$qbOrder->customer];
        // If the ID is set for nexternal customer then lets set it, if not then lets enter one
        // If not then lets create the customer and then store that ID in quickbooks for later use
        if (!empty($qb_customer->nexternalId)) {
            $customer = $nexternal->getCustomer($qb_customer->nexternalId);
        } else {
            $result = $nexternal->createCustomers(array($qb_customer), $qbOrder);
            if (!empty($result['errors'])) {
                $errors[] = sprintf("Could not create Customer[%s] for Order[%s] - %s", $qb_customer->type, $qbOrder->id, implode(" ", $result['errors']));
                $log->write(Log::ERROR, end($errors));
                continue;
            }
            if (empty($result['customers'])) {
                $errors[] = sprintf("An Unknown Error occurred when creating Customer[%s] for Order[%s] - %s",
                    $qb_customer->type,
                    $qbOrder->id,
                    print_r($result['errors'], true)
                );
                $log->write(Log::ERROR, end($errors));
                continue;
            }
            $customer = array_pop($result['customers']);
            // Add NX ID to QB Customer.
            if (false == $quickbooks->createCustomCustomerField($customer, "NexternalId", $customer->id, 'Customer')) {
                $errors[] = sprintf("Failed to add Nexternal ID[%s] to Quickbooks Customer[%s].", $customer->id, $qb_customer->id);
                $log->write(Log::ERROR, end($errors));
                continue;
            } else {
                $log->write(Log::INFO, sprintf("Nexternal Customer[%s] pushed to Quickbooks.", $customer->id));
                $qb_customer->nexternalId = $customer->nexternalId;
            }

            // Create Order.
            if (false !== ($oid = $neternal->createOrder($qbOrder, $qb_customer))) {
                $sentOrders[] = $oid;
            } else {
                $errors[] = sprintf("Failed to migrate Order[%s] to Nexternal.", $qbOrder->id);
                $log->write($log::ERROR, end($errors));
                continue;
            }
        }
    }
    return array('sentOrders' => $sentOrders, 'errors' => $errors);
}
