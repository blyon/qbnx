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


function testTaxCode(){
    $log            = Log::getInstance();
    $totalCustomers = 0;
    $totalOrders    = 0;

    // Connect to Nexternal.
    $nexternal = new NexternalController();
    $order = $nexternal->getOrderbyID('126377');
    $order = $order['0'];
    print_r($order);
	$quickbooks = new QuickbooksController();
	$qb_check = $quickbooks->RequestTaxItem('.16');
	if(!$qb_check)
	$quickbooks->CreateSalesTax('.16');
}


function testCreateCustomers() {
    $log            = Log::getInstance();
    $totalCustomers = 0;
    $totalOrders    = 0;

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
   
    // Test order FROM QB to Nexternal
    // Grab a test customer from QB to save into Nexternal
    //$customer = $quickbooks->getCustomerbyName('Farah Kraus');
    //$customer = $quickbooks->getCustomerbyName('Farah Kraus');  
    //$order = $quickbooks->getSalesReceiptByOrderId('N124275');
    //$new_cust = $nexternal->createCustomers(array($customer),$order['0']);

    $customer = $nexternal->getCustomer('17185');
    print_r($customer);
    
    $customer->firstName = 'Todd';
    $customer->lastName = 'Budzins';
    
    $order = $nexternal->getOrderbyID('126377');
    $order = $order['0'];
    print_r($order);
    
    $customer = $quickbooks->createCustomer($customer,$order);
    print_r($customer);
    
}


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
    $log            = Log::getInstance();
    $totalCustomers = 0;
    $totalOrders    = 0;

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
                    if(empty($order_check)) {
                    
                        // If the type is consumer use customer F
                        // If not search first by quickbooks id if set, if not then search by email and name
                        // If a customer is still not found then create it, if there was a error then dont send order
                        // If a customer was found then lets send the order over
                        
                        if($nx_customer->type == 'Consumer') {
                            $customer = $quickbooks->getCustomerbyName('f');
                        }
                        elseif(!empty($nx_customer->quickbooksId)) {
                            $customer = $quickbooks->getCustomer($nx_customer->quickbooksId);
                        }
                        else {
                            $fullname = $nx_customer ->firstName.' '.$nx_customer->lastName;
                            $customer = $quickbooks->getCustomerbyNameandEmail($fullname,$nx_customer->email);
                        }
                        if($customer == FALSE) {
                            $customer = $quickbooks->createCustomer($nx_customer,$nxOrder);
                        }
                        if($customer != FALSE) {
                            $quickbooks->addSalesReceipt($nxOrder, $customer);
                        }
                        else {
                            printf("Could not create customer for Order %d\n", $nxOrder->id);
                            unset($nxOrder);
                        }
                    }
                    else {
                        printf("Order %d Exists in QB\n", $nxOrder->id);
                        unset($nxOrder);
                    }
                }
            }
        } else {
            // Get Order Customers.
            foreach ($nxOrders as $nxOrder) {
                // Get Customer.
                if (!array_key_exists($nxOrder->customer, $nxCustomers)) {
                    $nxCustomers[$nxOrder->customer] = $nexternal->getCustomer($nxOrder->customer);
                }
            }
            print "Send orders to QB\n";
			foreach ($nxOrders as $nxOrder) {
				$nx_customer = $nxCustomers[$nxOrder->customer];
				$order_check = $quickbooks->getSalesReceiptByOrderId($nxOrder->id);
				if(empty($order_check)) {
				
					// If the type is consumer use customer F
					// If not search first by quickbooks id if set, if not then search by email and name
					// If a customer is still not found then create it, if there was a error then dont send order
					// If a customer was found then lets send the order over
					
					if($nx_customer->type == 'Consumer') {
						printf("Consumer cusomer found for order %d\n", $nxOrder->id);
						$customer = $quickbooks->getCustomerbyName('f');
					}
					elseif(!empty($nx_customer->quickbooksId)) {
						printf("Searching by List ID %d\n", $nxOrder->id);
						$customer = $quickbooks->getCustomer($nx_customer->quickbooksId);
					}
					else {
						$fullname = $nx_customer ->firstName.' '.$nx_customer->lastName;
						$customer = $quickbooks->getCustomerbyNameandEmail($fullname,$nx_customer->email);
						printf("Searching for customer by email for order %d\n", $nxOrder->id);
						if($customer != FALSE) {
							$quickbooks->createCustomCustomerField($customer, "NexternalId", $nx_customer->id, 'Customer');
						}
					}
					if($customer == FALSE) {
						printf("Creating customer for Order %d\n", $nxOrder->id);
						$customer = $quickbooks->createCustomer($nx_customer,$nxOrder);
					}
					if($customer != FALSE) {
					    printf("Adding to QB order %d\n", $nxOrder->id);
						$quickbooks->addSalesReceipt($nxOrder, $customer);
					}
					else {
						printf("Could not create customer for Order %d\n", $nxOrder->id);
						unset($nxOrder);
					}
				}
				else {
					printf("Order %d Exists in QB\n", $nxOrder->id);
					unset($nxOrder);
				}
			}
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
    $log            = Log::getInstance();
    $totalCustomers = 0;
    $totalOrders    = 0;

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
        $qbOrders    = $quickbooks->getSalesReceiptByDate($from, $to);
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
                        $nexternal->createOrder($qbOrder, $customer);
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
                    $nexternal->createOrder($qbOrder, $customer);
                }
            }
        }
        printf("Total Orders Sent to Nexternal: %d\n", $totalOrders);
    }
}
