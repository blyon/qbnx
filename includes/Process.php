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
    $totalOrders = 0;
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
        $totalOrders += count($nxOrders);
        $nxCustomers = array();

        // Check for Cache before sending orders to QB.
        if (file_exists(CACHE_DIR . NEXTERNAL_ORDER_CACHE . CACHE_EXT)) {
            // Save orders to cache and process cache.
            Util::writeCache(NEXTERNAL_ORDER_CACHE, serialize($nxOrders));
            while (null !== ($nxOrders = Util::readCache(NEXTERNAL_ORDER_CACHE))) {
                $errors = array_merge($errors, _pushNexternalToQuickbooks($nxOrders, $totalOrders, $nxCustomers, $nexternal, $quickbooks));
            }
        } else {
            $errors = array_merge($errors, _pushNexternalToQuickbooks($nxOrders, $totalOrders, $nxCustomers, $nexternal, $quickbooks));
        }
        printf("Total Orders Sent to QB: %d\n", $totalOrders);
    }

    // Send Email
    $message = sprintf("Total Orders Sent to QB: %d\n", $totalOrders);
    if (!empty($errors)) {
        Util::sendMail(MAIL_ERRORS, "Order ERROR Report for ToeSox", implode("\n", $errors));
    }
    Util::sendMail(MAIL_SUCCESS, "Order Report for ToeSox", $message);
}


/**
 * Helper function for pushNexternalToQuickbooks to prevent code duplication.
 *
 * @param type $nxOrders
 * @param type $totalOrders
 * @param type $nxCustomers
 * @param type $nexternal
 * @param type $quickbooks
 */
function _pushNexternalToQuickbooks(&$nxOrders, &$totalOrders, &$nxCustomers, &$nexternal, &$quickbooks) {
    $log    = Log::getInstance();
    $errors = array();
    $totalOrders += count($nxOrders);
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
            if (!$quickbooks->addSalesReceipt($nxOrder, $customer)) {
                $errors[] = sprintf("Could not create Order[%s] - %s", $nxOrder->id, $quickbooks->last_error);
                $log->write(Log::ERROR, end($errors));
                continue;
            }
        } else {
            printf("Order %d Exists in QB\n", $nxOrder->id);
            continue;
        }
    }
    return $errors;
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
    $log            = Log::getInstance();
    $totalCustomers = 0;
    $totalOrders    = 0;
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
        $totalOrders += count($qbOrders);
        $qbCustomers = array();

        // Check for Cache before sending orders to QB.
        if (file_exists(CACHE_DIR . QUICKBOOKS_ORDER_CACHE . CACHE_EXT)) {
            // Save orders to cache and process cache.
            Util::writeCache(QUICKBOOKS_ORDER_CACHE, serialize($qbOrders));
            $totalOrders = 0;
            while (null !== ($cacheOrders = Util::readCache(QUICKBOOKS_ORDER_CACHE))) {
                $qbOrders = unserialize($cacheOrders);
                unset($cacheOrders);
                $totalOrders += count($qbOrders);
                // Get Order Customers.
                foreach ($qbOrders as $qbOrder) {
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
                    if(!$empty($qb_customer->nexternalId)) {
                        $customer = $nexternal->getCustomer($qb_customer->nexternalId);
                    }
                    if($customer == FALSE || empty($qb_customer->nexternalId)) {
                        $customer = $nexternal->createCustomers(array($qb_customer),$qbOrder);
                        $quickbooks->createCustomCustomerField($customer, "NexternalId", $customer->id, 'Customer');
                        printf("Nexternal customer %d created\n", $customer->id);
                    }
                    if($customer == FALSE) {
                        $created_order = $nexternal->createOrder($qbOrder, $customer);
                    }

                    if($created_order == FALSE) {
                        $errors[] = "Could not create customer for Order ".$qbOrder->id;
                    }
                }
            }
        } else {
            foreach ($qbOrders as $qbOrder) {
                // Get Customer.
                if (!array_key_exists($qbOrder->customer, $qbCustomers)) {
                    $qbCustomers[$qbOrder->customer] = $quickbooks->getCustomer($qbOrder->customer);
                }
            }

            // Get Order Customers.
            foreach ($qbOrders as $key => $qbOrder) {
                // Ignore if Order initiated from Quickbooks.
                if (preg_match('/^N/', $qbOrder->id)) {
                    unset($qbOrders[$key]);
                    continue;
                }
            }

            print "Send orders to Nexternal\n";
            foreach ($qbOrders as $qbOrder) {
                $qb_customer = $qbCustomers[$qbOrder->customer];
                // If the ID is set for nexternal customer then lets set it, if not then lets enter one
                // If not then lets create the customer and then store that ID in quickbooks for later use
                if(!$empty($qb_customer->nexternalId)) {
                    $customer = $nexternal->getCustomer($qb_customer->nexternalId);
                }
                if($customer == FALSE || empty($qb_customer->nexternalId)) {
                    $customer = $nexternal->createCustomers(array($qb_customer),$qbOrder);
                    $quickbooks->createCustomCustomerField($customer, "NexternalId", $customer->id, 'Customer');
                    printf("Nexternal customer %d created\n", $customer->id);
                }

                if($customer == FALSE) {
                    $created_order = $nexternal->createOrder($qbOrder, $customer);
                }

                if($created_order == FALSE) {
                   $errors[] = "Could not create customer for Order ".$qbOrder->id;
                }

            }
        }
        printf("Total Orders Sent to Nexternal: %d\n", $totalOrders);
    }

    $to      = 'tbudzins@gmail.com';
    $subject = 'Order report for toesox';
    $headers = 'From: admin@toesox.com' . "\r\n" .
        'Reply-To: admin@toesox.com' . "\r\n" .
        'X-Mailer: PHP/' . phpversion();

    $message = sprintf("Total Orders Sent to Nexternal: %d\n", $totalOrders);
    if(!empty($errors))
        $message .= implode("\n",$errors);

    mail($to, $subject, $message, $headers);
}
