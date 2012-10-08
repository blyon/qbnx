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
    $errors = 0;

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
                $errors += $result['errors'];
            }
        } else {
            $result = _pushNexternalToQuickbooks($nxOrders, $nxCustomers, $nexternal, $quickbooks);
            $totalOrders = array_merge($totalOrders, $result['sentOrders']);
            $errors += $result['errors'];
        }
        printf("Total Orders Sent to QB: %d\n", count($totalOrders));
    }

    // Send Email
    $message = sprintf("Start Time: %s\nEnd Time: %s\n\nSync Orders From: %s To %s\n\nTotal Orders Sent to QB: %d\n\n\nOrder Numbers:\n\t%s", date('Y-m-d H:i:s', START_TIME), date('Y-m-d H:i:s'), date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to), count($totalOrders), implode("\n\t", $totalOrders));
    if (!empty($errors)) {
        $log = Log::getInstance();
        $log->sendMail(MAIL_ERRORS, "ERROR Report for (NX->QB)", "The following errors occurred while pushing Orders from Nexternal to Quickbooks.\n\n\n");
        $log->clearMail();
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
    $errors     = 0;
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
                    $msg = "[ORDER ".$nxOrder->id."] Could not create customer [".$nx_customer->type."] for Order. " . $quickbooks_last_error;
                    $log->mail($msg, Log::CATEGORY_QB_CUSTOMER);
                    $log->write(Log::ERROR, $msg);
                $errors++;
                    continue;
                }
            }
            if (!($salesReceipt = $quickbooks->addSalesReceipt($nxOrder, $customer))) {
                $msg = sprintf("[ORDER %s] Could not create Order: %s", $nxOrder->id, $quickbooks->last_error);
                $log->mail($msg, Log::CATEGORY_QB_CUSTOMER);
                $log->write(Log::ERROR, $msg);
                $errors++;
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
    $errors = 0;

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
        $qbOrders = $quickbooks->getInvoicesByDate($from, $to);

        // Check for Cache before sending orders to QB.
        if (file_exists(CACHE_DIR . QUICKBOOKS_ORDER_CACHE . CACHE_EXT)) {
            // Save orders to cache and process cache.
            Util::writeCache(QUICKBOOKS_ORDER_CACHE, serialize($qbOrders));
            while (null !== ($cacheOrders = Util::readCache(QUICKBOOKS_ORDER_CACHE))) {
                $qbOrders = unserialize($cacheOrders);
                unset($cacheOrders);
                $result = _pushQuickbooksToNexternal($qbOrders, $nxCustomers, $nexternal, $quickbooks);
                $totalOrders = array_merge($totalOrders, $result['sentOrders']);
                $errors += $result['errors'];
            }
        } else {
            $result = _pushQuickbooksToNexternal($qbOrders, $nxCustomers, $nexternal, $quickbooks);
            $totalOrders = $result['sentOrders'];
            $errors += $result['errors'];
        }
        printf("Total Orders Sent to NX: %d\n", count($totalOrders));
    }

    // Send Email
    $message = sprintf("Start Time: %s\nEnd Time: %s\n\nSync Orders From: %s To %s\n\nTotal Orders Sent to NX: %d\n\n\nOrder Numbers:\n\t%s", date('Y-m-d H:i:s', START_TIME), date('Y-m-d H:i:s'), date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to), count($totalOrders), implode("\n\t", $totalOrders));
    if (!empty($errors)) {
        $log = Log::getInstance();
        $log->sendMail(MAIL_ERRORS, "ERROR Report for (QB->NX)", "The following errors occurred while pushing Orders from Quickbooks to Nexternal.\n\n\n");
        $log->clearMail();
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
    $errors     = 0;
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
                $errors++;
                $msg = sprintf("[ORDER %s] Could not create Customer[%s]: %s", $qbOrder->id, $qb_customer->type, implode(", ", $result['errors']));
                $log->mail($msg, Log::CATEGORY_NX_CUSTOMER);
                $log->write(Log::ERROR, $msg);
                continue;
            }
            if (empty($result['customers'])) {
                $errors++;
                $msg = sprintf("[ORDER %s] An Unknown Error occurred when creating Customer[%s]: %s",
                    $qbOrder->id,
                    $qb_customer->type,
                    implode(", ", $result['errors'])
                );
                $log->mail($msg, Log::CATEGORY_NX_CUSTOMER);
                $log->write(Log::ERROR, $msg);
                continue;
            }
            $customer = array_pop($result['customers']);
            // Add NX ID to QB Customer.
            if (true !== ($result = $quickbooks->createCustomCustomerField($qb_customer->quickbooksId, "NexternalId", $customer->id, 'Customer'))) {
                $errors++;
                $msg = sprintf("[ORDER %s] Failed to add Nexternal ID[%s] to Quickbooks Customer[%s]. Reason: %s", $qbOrder->id, $customer->id, $qb_customer->id, $result);
                $log->mail($msg, Log::CATEGORY_NX_CUSTOMER);
                $log->write(Log::ERROR, $msg);
                continue;
            } else {
                $log->write(Log::INFO, sprintf("Nexternal Customer[%s] pushed to Quickbooks.", $customer->id));
                $qb_customer->nexternalId = $customer->nexternalId;
            }
        }

        // Create Order.
        if (false !== ($oid = $nexternal->createOrder($qbOrder, $qb_customer))) {
            $sentOrders[] = $oid;
        } else {
            $errors++;
            $msg = sprintf("[ORDER %s] Failed to migrate Order to Nexternal.", $qbOrder->id);
            $log->mail($msg, Log::CATEGORY_NX_ORDER);
            $log->write($log::ERROR, $msg);
            continue;
        }
    }
    return array('sentOrders' => $sentOrders, 'errors' => $errors);
}


/**
 * Push current Inventory from Quickbooks to Nexternal.
 *
 * @return boolean
 */
function pushInventoryToNexternal()
{
    $totalInventory = array();
    $errors = 0;

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

    $qbInventory = $quickbooks->getInventoryBySite("Main");
    // Check for Cache before sending inventory to QB.
    if (file_exists(CACHE_DIR . QUICKBOOKS_INV_CACHE . CACHE_EXT)) {
        // Save inventory to cache and process cache.
        Util::writeCache(QUICKBOOKS_INV_CACHE, serialize($qbInventory));
        while (null !== ($cacheInventory = Util::readCache(QUICKBOOKS_INV_CACHE))) {
            $qbInventory = unserialize($cacheInventory);
            unset($cacheInventory);
            $result = _pushInventoryToNexternal($qbInventory, $nexternal, $quickbooks);
            $totalInventory = array_merge($totalInventory, $result['sentInventory']);
            $errors += $result['errors'];
        }
    } else {
        $result = _pushInventoryToNexternal($qbInventory, $nexternal, $quickbooks);
        $totalInventory = $result['sentInventory'];
        $errors += $result['errors'];
    }
    printf("Total Inventory Items Sent to NX: %d\n", count($totalInventory));

    // Send Email
    //$message = sprintf("Start Time: %s\nEnd Time: %s\n\nSync Inventory From: %s To %s\n\nTotal Items Sent to NX: %d\n\n\nItems:\n\t%s", date('Y-m-d H:i:s', START_TIME), date('Y-m-d H:i:s'), date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to), count($totalInventory), implode("\n\t", $totalInventory));
    if (!empty($errors)) {
        $log = Log::getInstance();
        //$log->sendMail(MAIL_ERRORS, "ERROR Report for Inventory (QB->NX)", "The following errors occurred while pushing Inventory from Quickbooks to Nexternal.\n\n\n");
        $log->clearMail();
    }
    $log->sendMail("brandon@lyonaround.com", "Inventory Test", CATEGORY_NX_INVENTORY);
    //Util::sendMail(MAIL_SUCCESS, "Order Report for ToeSox Inventory (QB->NX)", $message);
}


/**
 * Helper function for pushQuickbooksToNexternal to prevent code duplication.
 *
 * @param type $qbInventory
 * @param type $nexternal
 * @param type $quickbooks
 * @return type
 */
function _pushInventoryToNexternal(&$qbInventory, &$nexternal, &$quickbooks) {
    $log        = Log::getInstance();
    $errors     = array();
    $sentItems = array();

    // Split the Inventory into arrays of 15 items.
    $itemGroup = (count($qbInventory) > 15)
        ? array_chunk($qbInventory, 15)
        : array($qbInventory);

    print "Send inventory to Nexternal\n";
    // Update Inventory.
    foreach ($itemGroup as $ig) {
        $response = $nexternal->updateInventory($ig);
        if (!empty($response['errors'])) {
            $errors = array_merge($errors, $response['errors']);
        }
        $sentItems = array_merge($sentItems, $response['items']);
        foreach ($response['items'] as $i) {
            $msg[] = sprintf("%s: %s", $i['sku'], $i['qty']);
        }
    }

    foreach ($errors as $e) {
        $msg = sprintf("[INVENTORY] %s", $e);
        $log->mail($msg, Log::CATEGORY_NX_INVENTORY);
        $log->write($log::ERROR, $msg);
    }

    return array('sentInventory' => $sentItems, 'errors' => $errors);
}
