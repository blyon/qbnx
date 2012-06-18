<?php
/**
 * Nexternal Controller Class.
 *
 * PHP version 5.3
 *
 * @author   Brandon Lyon <brandon@lyonaround.com>
 * @version  GIT:<git_id>
 */

// Include necessary Models.
require_once dirname(__FILE__) . '/../models/Log.php';
require_once dirname(__FILE__) . '/../models/Nexternal.php';


class NexternalController
{
    private $_nx;
    public  $log;


    /**
     * Authenticate with Quickbooks Server.
     *
     * @return mixed Quickbooks object or false.
     */
    public function __construct()
    {
        $this->_nx = Nexternal::getInstance();
        $this->log = $this->_nx->log;
    }


    /**
     * Authenticate with Nexternal Server.
     *
     * @return boolean
     */
    public function authenticate()
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);

        if (Nexternal::AUTHSTEP_INACTIVE === $this->_nx->authStep) {
            $this->log->write(Log::INFO, "Authenticating");
            $dom = $this->_sendAuthentication();
            if (!$this->_processAuthenticationResponse($dom)) {
                $this->log->write(Log::CRIT, "Authention Failed!");
                return false;
            }
        }
        if (Nexternal::AUTHSTEP_PENDING === $this->_nx->authStep) {
            $this->log->write(Log::INFO, "Sending Verification");
            $dom = $this->_sendVerification();
            if (!$this->_processVerificationResponse($dom)) {
                $this->log->write(Log::CRIT, "Verification Failed!");
                return false;
            }
        }
        if (Nexternal::AUTHSTEP_ACTIVE !== $this->_nx->authStep) {
            $this->log->write(Log::CRIT, "Failed to Authenticate!" . $this->_nx->authStep);
            return false;
        }
        return true;
    }


    /**
     * Retrieve a list of Customers.
     *
     * @param type $from Beginning Time
     * @param type $to   End Time
     *
     * @return array of Customers.
     */
    public function getCustomers($from, $to)
    {
        // Query Customers.
        $page      = 0;
        $morePages = true;
        $customers = array();
        while ($morePages) {
            $page++;
            $response = $this->_processCustomerQueryResponse(
                $this->_customerQuery($from, $to, $page)
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
                Util::writeCache(NEXTERNAL_CUSTOMER_CACHE, serialize($customers));
                $customers = array();
            }
        }

        return $customers;
    }


    /**
     * Download Orders from Nexternal for a given date range.
     *
     * @param integer $from Beginning Time
     * @param integer $to   End Time
     *
     * @return array of Orders.
     */
    public function getOrders($from, $to)
    {
        // Query "paid" Nexternal Orders.
        $page      = 0;
        $morePages = true;
        $orders    = array();
        while ($morePages) {
            $page++;
            $response = $this->_processOrderQueryResponse(
                $this->_orderQuery(
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
                Util::writeCache(NEXTERNAL_ORDER_CACHE, serialize($orders));
                $orders = array();
            }
        }

        return $orders;
    }


    /**
     * Create Customers on Nexternal.
     *
     * Note: Only 15 customers can be created per transaction, the customers
     * array will be processed in blocks of 15.
     *
     * @param boolean
     */
    public function createCustomers($customers)
    {
        // Get the arraykey of the last customer.
        $lastCustomer = end(array_keys($customers));

        // Loop over the customers array.
        foreach ($customers as $cid => $customer) {
            // Make sure this is a valid customer object.
            if (!($customer instanceof Customer)) {
                $this->log->write(Log::CRIT, "One or more customers passed to ".__FUNCTION__." is not an instance of Customer");
            }
            // Add Customer to Queue.
            $this->_customerCreate($customer);

            // Send XML to Nexternal if we have 15 customers in the queue, or if
            // this is the last customer in the array.
            $customersInQueue = count($this->nx->dom->children('Customer'));
            if (Nexternal::CUSTUPDATE_MAX == $customersInQueue
                || $cid == $lastCustomer
            ) {
                $response = $this->_processCustomerCreateResponse($this->_nx->sendDom('customerupdate.rest'));
                if (!empty($response['errors'])) {
                    return false;
                }
                if (count($response['customers']) != $customersInQueue) {
                    $this->log->write(Log::ERROR, sptrinf("The number of customers returned from CustomerCreate[%d] does not match the number of Customers Sent[%d]", count($response['customers'], $customersInQueue)));
                }
            }
        }

        return true;
    }


    /**
     * Create Orders on Nexternal.
     *
     * Note: Only 15 orders can be created per transaction, the orders array
     * will be processed in blocks of 15.
     *
     * @param boolean
     */
    public function createOrders($orders)
    {
        // Get the arraykey of the last customer.
        $lastOrder = end(array_keys($orders));

        // Loop over the customers array.
        foreach ($orders as $cid => $order) {
            // Make sure this is a valid customer object.
            if (!($order instanceof Order)) {
                $this->log->write(Log::CRIT, "One or more orders passed to ".__FUNCTION__." is not an instance of Order");
            }
            // Add Order to Queue.
            $this->_orderCreate($order);

            // Send XML to Nexternal if we have 15 customers in the queue, or if
            // this is the last customer in the array.
            $ordersInQueue = count($this->nx->dom->children('Order'));
            if (Nexternal::ORDERUPDATE_MAX == $ordersInQueue
                || $cid == $lastOrder
            ) {
                $response = $this->_processOrder($this->_nx->sendDom('customerupdate.rest'));
                if (!empty($response['errors'])) {
                    return false;
                }
                if (count($response['orders']) != $ordersInQueue) {
                    $this->log->write(Log::ERROR, sptrinf("The number of orders returned from Order[%d] does not match the number of Order Sent[%d]", count($response['corders'], $ordersInQueue)));
                }
            }
        }

        return true;
    }


    /**
     * Send Authentication Request to Nexternal.
     *
     * @return SimpleXML object.
     */
    private function _sendAuthentication()
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);

        // Initialize DOM {@see _addCredentials}.
        $this->_nx->initDom('<TestSubmitRequest/>');

        // Send XML to Nexternal.
        $responseDom = $this->_nx->sendDom('testsubmit.rest');

        // Check for Error.
        if (false == $responseDom) {
            $this->log->write(Log::ERROR, "Authentication Failed");
            throw new Exception("Authentication Failed");
        }

        return $responseDom;
    }


    /**
     * Process Authentication Response.
     * Set Auth Key and KeyType from Authentication Response.
     *
     * @param SimpleXml Object  $responseDom
     *
     * @return boolean
     */
    private function _processAuthenticationResponse($responseDom)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);

        // Check for Errors.
        if (count($responseDom->children('Errors'))) {
            $this->_nx->authStep = Nexternal::AUTHSTEP_INACTIVE;
            $this->log->write(Log::ERROR, sptrintf("Error From Nexternal: %s", (string) $responseDom->Errors->ErrorDescription));
            return false;
        }

        // Get Key Type.
        foreach ($responseDom->attributes() as $attribute => $value) {
            if ($attribute == 'Type') {
                $this->_nx->keyType = $value;
                $this->log->write(Log::NOTICE, "Authentication Key Type set to: " . $this->_nx->keyType);
                break;
            }
        }

        // Get Key.
        $this->_nx->key = $responseDom->TestKey;
        $this->log->write(Log::NOTICE, "Authentication Key set to: " . $this->_nx->key);

        // Update _authStep.
        $this->_nx->authStep = Nexternal::AUTHSTEP_PENDING;

        // Return boolean if both key and keyType are set.
        return (!empty($this->_nx->key) && !empty($this->_nx->keyType));
    }


    /**
     * Send Authentication Verification.
     *
     * @return SimpleXml Object
     */
    private function _sendVerification()
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);

        // Initialize DOM {@see _addCredentials}.
        $this->_nx->initDom('<TestVerifyRequest/>');

        // Send XML to Nexternal.
        $responseDom = $this->_nx->sendDom('testverify.rest');

        // Check for Error.
        if (false == $responseDom) {
            $this->log->write(Log::ERROR, "Authentication Verification Failed");
            throw new Exception("Authentication Verification Failed");
        }

        return $responseDom;
    }


    /**
     * Process Authentication Verification Response.
     * Set Authentication Active Key.
     *
     * @param SimpleXml $responseDom
     *
     * @return boolean
     */
    private function _processVerificationResponse($responseDom)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);

        // Check for Errors.
        if (count($responseDom->children('Errors'))) {
            $this->_nx->authStep = Nexternal::AUTHSTEP_INACTIVE;
            $this->log->write(Log::ERROR, sptrintf("Error From Nexternal: %s", (string) $responseDom->Errors->ErrorDescription));
            return false;
        }

        // Update _authStep.
        $this->_nx->authStep = Nexternal::AUTHSTEP_ACTIVE;

        // Update key.
        $this->_nx->activeKey = $responseDom->ActiveKey;

        // Return boolean if activeKey is set.
        return (!empty($this->_nx->activeKey));
    }


    /**
     * Query Nexternal for Orders.
     *
     * @param integer $startDate
     * @param integer $endDate
     * @param string  $billingStatus
     * @param integer $page
     *
     * @return SimpleXml response.
     */
    private function _orderQuery($startDate, $endDate, $billingStatus=null, $page=1)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);

        // Initialize DOM {@see _addCredentials}.
        $this->_nx->initDom('<OrderQueryRequest/>');

        // Add Date Range Filter.
        $this->_nx->dom->addChild('OrderUpdRange')->
            addChild('OrderUpdStart')->
            addChild('DateTime')->
            addChild('Date', date('m/d/Y', $startDate));
        $this->_nx->dom->OrderUpdRange->OrderUpdStart->DateTime->addChild('Time', date('H:i', $startDate));
        $this->_nx->dom->OrderUpdRange->
            addChild('OrderUpdEnd')->
            addChild('DateTime')->
            addChild('Date', date('m/d/Y', $endDate));
        $this->_nx->dom->OrderUpdRange->OrderUpdEnd->DateTime->addChild('Time', date('H:i', $endDate));

        // Add Billing Status Filter.
        if (!is_null($billingStatus)) {
            if (in_array($billingStatus, array(Nexternal::BILLSTAT_UNBILLED, Nexternal::BILLSTAT_AUTHORIZED,
                Nexternal::BILLSTAT_BILLED, Nexternal::BILLSTAT_PARTIALBILL, Nexternal::BILLSTAT_PAID,
                Nexternal::BILLSTAT_PARTIALPAID, Nexternal::BILLSTAT_REFUNDED, Nexternal::BILLSTAT_PARTIALREFUND,
                Nexternal::BILLSTAT_DECLINED, Nexternal::BILLSTAT_CC, Nexternal::BILLSTAT_CANCELED,
            ))) {
                $this->_nx->dom->addChild('BillingStatus', $billingStatus);
            } else {
                $this->log->write(Log::CRIT, "Unable to send Order Query, invalid Billing Status: " . $billingStatus);
                throw new Exception("Unable to send Order Query, invalid Billing Status: " . $billingStatus);
            }
        }

        // Add Page Filter.
        $this->_nx->dom->addChild('Page', $page);

        // Send XML to Nexternal.
        $responseDom = $this->_nx->sendDom('orderquery.rest');

        // Return Response Dom.
        return $responseDom;
    }


    /**
     * Process Order Query Response.
     *
     * @param SimpleXml
     *
     * @return array
     */
    private function _processOrderQueryResponse($dom)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);

        $return = array(
            'morePages' => false,
            'orders'    => array(),
            'errors'    => array(),
        );

        // Process Errors.
        if (count($dom->children('Errors'))) {
            $this->_nx->authStep = Nexternal::AUTHSTEP_INACTIVE;
            $this->log->write(Log::ERROR, sptrintf("Error From Nexternal: %s", (string) $dom->Errors->ErrorDescription));
            $return['errors'][] = (string) $responseDom->Errors->ErrorDescription;
            return $return;
        }

        // Check for More Pages.
        $return['morePages'] = isset($dom->NextPage);

        // Process Orders.
        if (isset($dom->Order)) {
            foreach ($dom->Order as $order) {
                $o = new Order;
                $o->id              = (string) $order->OrderNo;
                $o->timestamp       = strtotime((string) $order->OrderDate->DateTime->Date . ' '
                    . (string) $order->OrderDate->DateTime->Time);
                $o->type            = (string) $order->OrderType;
                $o->status          = (string) $order->OrderStatus;
                $o->subTotal        = (string) $order->OrderNet;
                $o->taxTotal        = (string) $order->SalesTax->SalesTaxTotal;
                $o->shipTotal       = (string) $order->ShipRate;
                $o->total           = (string) $order->OrderAmount;
                $o->memo            = (string) $order->Comments->CompanyComments;
                $o->location        = (string) $order->PlacedBy;
                $o->ip              = (string) $order->IP->IPAddress;
                $o->paymentStatus   = (string) $order->BillingStatus;
                $o->paymentMethod['type'] = (string) $order->Payment->PaymentMethod;
                if ($o->paymentMethod['type'] == 'Credit Card') {
                    $o->paymentMethod['cardType']  = (string) $order->Payment->CreditCard->CreditCardType;
                    $o->paymentMethod['cardNumber']= (string) $order->Payment->CreditCard->CreditCardNumber;
                    $o->paymentMethod['cardExp']   = (string) $order->Payment->CreditCard->CreditCardExpDate;
                }
                $o->customer        = (string) $order->Customer->CustomerNo;
                $o->billingAddress  = array(
                    'firstName'=> (string) $order->BillTo->Address->Name->FirstName,
                    'lastName' => (string) $order->BillTo->Address->Name->LastName,
                    'company'  => (string) $order->BillTo->Address->CompanyName,
                    'address'  => (string) $order->BillTo->Address->StreetAddress1,
                    'address2' => (string) $order->BillTo->Address->StreetAddress2,
                    'city'     => (string) $order->BillTo->Address->City,
                    'state'    => (string) $order->BillTo->Address->StateProvCode,
                    'zip'      => (string) $order->BillTo->Address->ZipPostalCode,
                    'country'  => (string) $order->BillTo->Address->CountryCode,
                    'phone'    => (string) $order->BillTo->Address->PhoneNumber,
                );
                $o->shippingAddress  = array(
                    'firstName'=> (string) $order->ShipTo->Address->Name->FirstName,
                    'lastName' => (string) $order->ShipTo->Address->Name->LastName,
                    'company'  => (string) $order->ShipTo->Address->CompanyName,
                    'address'  => (string) $order->ShipTo->Address->StreetAddress1,
                    'address2' => (string) $order->ShipTo->Address->StreetAddress2,
                    'city'     => (string) $order->ShipTo->Address->City,
                    'state'    => (string) $order->ShipTo->Address->StateProvCode,
                    'zip'      => (string) $order->ShipTo->Address->ZipPostalCode,
                    'country'  => (string) $order->ShipTo->Address->CountryCode,
                    'phone'    => (string) $order->ShipTo->Address->PhoneNumber,
                );

                // Add Product(s).
                if (isset($order->ShipTo->ShipFrom->LineItem)) {
                    foreach ($order->ShipTo->ShipFrom->LineItem as $lineItem) {
                        $o->products[] = array(
                            'sku'       => (string) $lineItem->LineProduct->ProductSKU,
                            'name'      => (string) $lineItem->LineProduct->ProductName,
                            'qty'       => (string) $lineItem->Quantity,
                            'price'     => (string) $lineItem->ExtPrice,
                            'tracking'  => (string) $lineItem->TrackingNumber,
                        );
                    }
                }

                // Add Discount(s).
                if (isset($order->Discount)) {
                    foreach ($order->Discount as $discount) {
                        $o->discounts[] = array(
                            'type'  => (string) $discount->Type,
                            'name'  => (string) $discount->Name,
                            'value' => (string) $discount->Value,
                        );
                    }
                }

                // Add Gift Certificate(s).
                if (isset($order->GiftCert)) {
                    foreach ($order->GiftCert as $gc) {
                        $o->giftCerts[] = array(
                            'code'   => (string) $gc->GiftCertCode,
                            'amount' => (string) $gc->GiftCertAmount,
                        );
                    }
                }

                $return['orders'][] = $o;
            }
        }

        return $return;
    }


    /**
     * Query Nexternal for Customers.
     *
     * @param integer $startDate
     * @param integer $endDate
     * @param integer $page
     *
     * @return SimpleXml Object
     */
    private function _customerQuery($startDate, $endDate, $page=1)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);

        // Initialize DOM {@see _addCredentials}.
        $this->_nx->initDom('<CustomerQueryRequest/>');

        // Add Date Range Filter.
        $this->_nx->dom->addChild('CustomerUpdRange')->
            addChild('CustomerUpdStart')->
            addChild('DateTime')->
            addChild('Date', date('m/d/Y', $startDate));
        $this->_nx->dom->CustomerUpdRange->CustomerUpdStart->DateTime->addChild('Time', date('H:i', $startDate));
        $this->_nx->dom->CustomerUpdRange->
            addChild('CustomerUpdEnd')->
            addChild('DateTime')->
            addChild('Date', date('m/d/Y', $startDate));
        $this->_nx->dom->CustomerUpdRange->CustomerUpdEnd->DateTime->addChild('Time', date('H:i', $endDate));

        // Add Page Filter.
        $this->_nx->dom->addChild('Page', $page);

        // Send XML to Nexternal.
        $responseDom = $this->_nx->sendDom('customerquery.rest');

        // Return Response Dom.
        return $responseDom;
    }


    /**
     * Process Customer Query Response.
     *
     * @param SimpleXml
     *
     * @return array
     */
    private function _processCustomerQueryResponse($dom)
    {
        $return = array(
            'morePages' => false,
            'customers' => array(),
            'errors'    => array(),
        );

        // Process Errors.
        if (count($dom->children('Errors'))) {
            $this->_nx->authStep = Nexternal::AUTHSTEP_INACTIVE;
            $this->log->write(Log::ERROR, sptrintf("Error From Nexternal: %s", (string) $dom->Errors->ErrorDescription));
            $return['errors'][] = (string) $responseDom->Errors->ErrorDescription;
            return $return;
        }


        // Check for More Pages.
        $return['morePages'] = isset($dom->NextPage);

        // Process Customers.
        if (isset($dom->Customer)) {
            foreach ($dom->Customer as $customer) {
                $c = new Customer;
                $c->id      = (string) $customer->CustomerNo;
                $c->email   = (string) $customer->Email;
                $c->type    = (string) $customer->CustomerType;
                $c->address  = array(
                    'type'     => (string) $customer->Address->Type,
                    'firstName'=> (string) $customer->Address->Name->FirstName,
                    'lastName' => (string) $customer->Address->Name->LastName,
                    'company'  => (string) $order->Address->CompanyName,
                    'address'  => (string) $order->Address->StreetAddress1,
                    'address2' => (string) $order->Address->StreetAddress2,
                    'city'     => (string) $order->Address->City,
                    'state'    => (string) $order->Address->StateProvCode,
                    'zip'      => (string) $order->Address->ZipPostalCode,
                    'country'  => (string) $order->Address->CountryCode,
                    'phone'    => (string) $order->Address->PhoneNumber,
                );

                $return['customers'][] = $c;
            }
        }

        return $return;
    }


    /**
     * Prepare Customer for creation on Nexternal.
     *
     * @param Customer $customer Valid Customer to add.
     *
     * @return boolean TRUE if customer added to request queue.
     */
    private function _customerCreate(Customer $customer)
    {
        if ($this->_nx->dom->getName() != 'CustomerUpdateRequest') {
            // Initialize DOM {@see _addCredentials}.
            $this->_nx->initDom('<CustomerUpdateRequest/>');
        }

        // Make sure we won't go over the maximum number of customers per request.
        if (1 + count($this->_nx->dom->children('Customer') > Nexternal::CUSTUPDATE_MAX)) {
            $this->log->write(Log::ERROR, sptrinf("Maximum number of Customers [%d] already added to CustomerUpdateRequest", Nexternal::CUSTUPDATE_MAX));
            return false;
        }

        // Add Customer to DOM.
        $nxCust = $this->_nx->dom->addChild('Customer')->addAttribute('Mode', 'Add')->addAttribute('MatchingField', 'Email');
        $nxCust->addChild('Name');
        $nxCust->Name->addChild('FirstName', $customer->firstName);
        $nxCust->Name->addChild('LastName', $customer->lastName);
        $nxCust->addChild('Email', $customer->email);
        $nxCust->addChild('CustomerType', $customer->type);

        return true;
    }


    /**
     * Process Customer Create Response.
     *
     * @param SimpleXml
     *
     * @return array
     */
    private function _processCustomerCreateResponse($dom)
    {
        $return = array(
            'customers' => array(),
            'errors'    => array(),
        );

        // Process Errors.
        if (count($dom->children('Errors'))) {
            $this->_nx->authStep = Nexternal::AUTHSTEP_INACTIVE;
            $this->log->write(Log::ERROR, sptrintf("Error From Nexternal: %s", (string) $dom->Errors->ErrorDescription));
            $return['errors'][] = (string) $responseDom->Errors->ErrorDescription;
            return $return;
        }


        // Check for More Pages.
        $return['morePages'] = isset($dom->NextPage);

        // Process Customers.
        if (isset($dom->Customer)) {
            foreach ($dom->Customer as $customer) {
                $c = new Customer;
                $c->id      = (string) $customer->CustomerNo;
                $c->email   = (string) $customer->Email;
                $c->type    = (string) $customer->CustomerType;

                $return['customers'][] = $c;
            }
        }

        return $return;
    }


    /**
     * Send Order to Nexternal.
     *
     * @param Order    $order
     * @param Customer $customer
     *
     * @return SimpleXml Object
     */
    private function _orderCreate(Order $order, Customer $customer)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__."(".$order->id.")");

        // Make sure we won't go over the maximum number of customers per request.
        if (1 + count($this->_nx->dom->children('Customer') > Nexternal::CUSTUPDATE_MAX)) {
            $this->log->write(Log::ERROR, sptrinf("Maximum number of Customers [%d] already added to CustomerUpdateRequest", Nexternal::CUSTUPDATE_MAX));
            return false;
        }

        // Initialize DOM {@see _addCredentials}.
        $this->_initDom('<OrderCreate/>');
        // Let Nexternal know these orders are from an external system.
        $this->dom->OrderCreate->addAttribute('Mode', 'Import');

        $t = $this->dom->OrderCreate->addChild('OrderDate')->addChild('DateTime');
        $t->addChild('Date', date('m/d/Y', $order->timestamp));
        $t->addChild('Time', date('h:i', $order->timestamp));

        // Add Customer.
        $c = $this->dom->OrderCreate->addChild('Customer');
        $c->addAttribute('MatchingField', 'CustomerNo');
        $c->addChild('CustomerNo', $customer->id);

        // Add Shipping Info.
        $s = $this->dom->OrderCreate->addChild('ShipTos')->addChild('ShipTo');
        $s->addChild('Address')->addChild('Name');
        $s->Address->Name->addChild('FirstName', $order->billingAddress['firstName']);
        $s->Address->Name->addChild('LastName', $order->billingAddress['lastName']);
        $s->Address->addChild('CompanyName', $order->billingAddress['company']);
        $s->Address->addChild('StreetAddress1', $order->billingAddress['address']);
        $s->Address->addChild('StreetAddress2', $order->billingAddress['address2']);
        $s->Address->addChild('City', $order->billingAddress['city']);
        $s->Address->addChild('StateProvCode', $order->billingAddress['state']);
        $s->Address->addChild('ZipPostalCode', $order->billingAddress['zip']);
        $s->Address->addChild('CountryCode', $order->billingAddress['country']);
        $s->Address->addChild('PhoneNumber', $order->billingAddress['phone']);

        // Add Products.
        $s->addChild('Products');
        foreach ($order->products as $product) {
            $p = $s->Products->addChild('Product');
            $p->addChild('ProductSKU', $product->sku);
            $p->addChild('Qty', $product->qty);
            $p->addChild('UnitPrice', $product->price);
        }

        // Add Discounts.
        if (isset($order->discounts) && !empty($order->discounts)) {
            // for some reason you can only apply 1 discount....
            $sum = 0;
            foreach ($order->discounts as $discount) {
                $sum += $discount['value'];
            }
            if ($sum) {
                $this->dom->OrderCreate->addChild('Discounts')->addChild('OrderDiscount', $sum);
            }
        }

        // Add Gift Certificates.
        if (isset($order->giftCerts) && !empty($order->giftCerts)) {
            $this->dom->OrderCreate->addChild('GiftCertificates');
            foreach ($order->giftCerts as $cert) {
                $gc = $this->dom->OrderCreate->GiftCertificates->addChild('GiftCert');
                $gc->addChild('GiftCertAmount', $cert->amount);
                $gc->addChild('GiftCertMessage', $cert->code);
                $gc->addChild('GiftCertRecipient')->addChild('Name');
                $gc->GiftCertRecipient->Name->addChild('FirstName', $customer->firstName);
                $gc->GiftCertRecipient->Name->addChild('LastName', $customer->lastName);
            }
        }

        // Add Billing Info.
        $a = $this->dom->OrderCreate->addChild('BillTo')->addChild('Address');
        $a->addChild('Name');
        $a->Name->addChild('FirstName', $order->billingAddress['firstName']);
        $a->Name->addChild('LastName', $order->billingAddress['lastName']);
        $a->addChild('CompanyName', $order->billingAddress['company']);
        $a->addChild('StreetAddress1', $order->billingAddress['address']);
        $a->addChild('StreetAddress2', $order->billingAddress['address2']);
        $a->addChild('City', $order->billingAddress['city']);
        $a->addChild('StateProvCode', $order->billingAddress['state']);
        $a->addChild('ZipPostalCode', $order->billingAddress['zip']);
        $a->addChild('CountryCode', $order->billingAddress['country']);
        $a->addChild('PhoneNumber', $order->billingAddress['phone']);

        // Send XML to Nexternal.
        $responseDom = $this->_sendDom('ordercreate.rest');

        // Return Response Dom.
        return $responseDom;
    }


    /**
     * Process Order Create Response.
     *
     * @param SimpleXml
     *
     * @return array
     */
    public function orderCreateResponse($dom)
    {
        $return = array(
            'order'     => array(),
            'errors'    => array(),
        );

        $return['order'] = array(
            'status'        => (string) $dom->Order->OrderStatus,
            'billingStatus' => (string) $dom->Order->BillingStatus,
        );

        foreach ($dom->Order->attributes() as $attribute => $value) {
            if ($attribute == 'No') {
                $return['order']['id'] = $value;
                break;
            }
        }

        if (isset($dom->Order->Warning)) {
            $return['errors'][] = (string) $dom->Order->Warning;
        }

        return $return;
    }

}
