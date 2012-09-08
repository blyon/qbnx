<?php
/**
 * Quickbooks Controller Class.
 *
 * PHP version 5.3
 *
 * @author  Brandon Lyon <brandon@lyonaround.com>
 * @version GIT:<git_id>
 */

// Include necessary Models.
require_once dirname(__FILE__) . '/../models/Log.php';
require_once dirname(__FILE__) . '/../models/Quickbooks.php';


class QuickbooksController
{
    const TAXCODE_SUFFIX = 'sbe';
    private $_qb;
    public $log;
    public $last_error;

    /**
     * Authenticate with Quickbooks Server.
     *
     * @return mixed Quickbooks object or false.
     */
    public function __construct()
    {
        $this->_qb = Quickbooks::getInstance();
        $this->log = $this->_qb->log;
    }


    /**
     * Get Customer from Quickbooks
     */
    public function getCustomer($id)
    {
        $customer = $this->_processCustomerQueryResponse(
            $this->_createCustomerQuery($id)
        );
        if (empty($customer)) {
            $this->log->write(Log::NOTICE, sprintf("Could not find Customer by Name[%s].", $name));
            return false;
        }
        return array_shift($customer);
    }


     /**
     * Get create custom field for customer
     */
    public function createCustomCustomerField($customer, $dataExtName, $dataExtValue, $table_name)
    {
        return $this->_createCustomField($customer, $dataExtName, $dataExtValue, $table_name);
    }


    /**
     * Get Customer from Quickbooks by full name
     */
    public function getCustomerbyName($name)
    {
        $customer = $this->_processCustomerQueryResponse(
            $this->_createCustomerQueryFullName($name)
        );
        if (empty($customer)) {
            $this->log->write(Log::NOTICE, sprintf("Could not find Customer by Name[%s].", $name));
            return false;
        }
        return array_shift($customer);
    }


    /**
     * Get Sales Receipt by Quickbooks Transaction ID.
     *
     * @param type $txn Quickbooks Transaction ID
     *
     * @return array Array of Order Objects.
     */
    public function getSalesReceiptByTxn($txn)
    {
        return $this->_processSalesReceiptQueryResponse(
            $this->_createSalesReceiptQuery($txn, null, null)
        );
    }


    /**
     * Create customer in QB
     *
     * @param customer
     *
     * @return array Array of Order Objects.
     */
    public function createCustomer($customer, $order)
    {
        return $this->_processCustomerCreateResponse(
            $this->_createCustomer($customer,$order)
        );
    }


    /**
     * Get Sales Receipt by Order ID.
     *
     * @param type $id Order ID
     *
     * @return array Array of Order Objects.
     */
    public function getSalesReceiptByOrderId($id)
    {
        return $this->_processSalesReceiptQueryResponse(
            $this->_createSalesReceiptQuery(null, $id, null)
        );
    }


    /**
     * Get Invoices by Date.
     *
     * @param integer $from Start Date
     * @param integer $to   End Date
     *
     * return array Array of Order Objects.
     */
    public function getInvoicesByDate($from, $to)
    {
        return $this->_processInvoiceQueryResponse(
            $this->_createInvoiceQuery(null, null, array('from' => $from, 'to' => $to))
        );
    }


    /**
     * Get Sales Receipt by Date.
     *
     * @param integer $from Start Date
     * @param integer $to   End Date
     *
     * @return array Array of Order Objects.
     */
    public function getSalesReceiptByDate($from, $to)
    {
        return $this->_processSalesReceiptQueryResponse(
            $this->_createSalesReceiptQuery(null, null, array('from' => $from, 'to' => $to))
        );
    }


    public function addSalesReceipt($order, $customer)
    {
        $resp = $this->_createSalesReceiptFromOrder($order, $customer);
        return $this->_processSalesReceiptAddResponse($resp);
    }


    /**
     * Update Sales Receipt from Order.
     *
     * @param type  $txn   Quickbooks Transaction ID
     * @param Order $order Values to set to Order.
     */
    public function updateSalesReceipt($txn, $order)
    {
        return $this->_processSalesReceiptUpdateResponse(
            $this->_createSalesReceiptUpdate($txn, $order)
        );
    }


    private function _createCustomerQuery($listId)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__."(".$listId.")");

        $query = $this->_qb->request->AppendCustomerQueryRq();
        $query->OwnerIDList->add(0);
        $query->ORCustomerListQuery->ListIDList->add($listId);
        return $this->_qb->sendRequest();
    }


    private function _createCustomerQueryFullName($name)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__."(".$name.")");

        $query = $this->_qb->request->AppendCustomerQueryRq();
        $query->OwnerIDList->add(0);
        $query->ORCustomerListQuery->FullNameList->add($name);
        return $this->_qb->sendRequest();
    }


    private function _processCustomerQueryResponse($response,$email = FALSE)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);
        $customers = array();

        if (!$response->ResponseList->Count) {
            $this->log->write(Log::ERROR, "Failed to retrieve Customer List");
            return $customers;
        }
        if (0 != $response->ResponseList->GetAt(0)->StatusCode) {
            $this->log->write(Log::NOTICE, "No Customers found");
            $this->log->write(Log::NOTICE, "Response From Quickbooks: " . $response->ResponseList->GetAt(0)->StatusMessage);
            return $customers;
        }

        for ($i=0; $i<$response->ResponseList->Count; $i++) {
            $details = &$response->ResponseList->GetAt($i)->Detail;

            for ($n=0; $n<$details->Count; $n++) {
                $d = $details->GetAt($n);
                if (!$email || ($this->_getValue($d,'Email') == $email)) {
                    $c = new Customer;
                    $c->company     = $this->_getValue($d,'CompanyName');
                    $c->type        = $this->_getValue($d->CustomerTypeRef,'FullName');
                    $c->email       = $this->_getValue($d,'Email');
                    $c->quickbooksId= $this->_getValue($d,'ListID');
                    $c->firstName   = $this->_getValue($d,'FirstName');
                    $c->lastName    = $this->_getValue($d,'LastName');
                    $c->fullName    = $this->_getValue($d,'FullName');
                    if ($d->DataExtRetList) {
                        for ($e=0; $e<$d->DataExtRetList->Count; $e++) {
                            if ("NexternalId" == $d->DataExtRetList->GetAt($e)->DataExtName->getValue) {
                                $c->nexternalId = $d->DataExtRetList->GetAt($e)->DataExtValue->getValue;
                            }
                        }
                    }
                    $customers[] = $c;
                }
                // write Customers to File if we've reached our cache cap.
                if (MEMORY_CAP <= memory_get_usage()) {
                    Util::writeCache(QUICKBOOKS_CUSTOMER_CACHE, $customers);
                    $customers = array();
                }
            }
        }

        return $customers;
    }


    private function _processCustomerCreateResponse($response)
    {
        if (0 != $response->ResponseList->GetAt(0)->StatusCode) {
            $this->log->write(Log::NOTICE, "customer not created");
            $this->log->write(Log::NOTICE, "Response From Quickbooks: " . $response->ResponseList->GetAt(0)->StatusMessage);
            $this->last_error = $response->ResponseList->GetAt(0)->StatusMessage;
            return false;
        }

        // Detail is not an array, only 1 object is ever returned.
        $d = $response->ResponseList->GetAt(0)->Detail;

        $c = new Customer;
        $c->company        = $this->_getValue($d,'CompanyName');
        $c->type           = $this->_getValue($d->CustomerTypeRef,'FullName');
        $c->email          = $this->_getValue($d,'Email');
        $c->quickbooksId   = $this->_getValue($d,'ListID');
        $c->firstName      = $this->_getValue($d,'FirstName');
        $c->lastName       = $this->_getValue($d,'LastName');
        $c->fullName       = $this->_getValue($d,'FullName');
        if ($d->DataExtRetList) {
            for ($e=0; $e<$d->DataExtRetList->Count; $e++) {
                if ("NexternalId" == $d->DataExtRetList->GetAt($e)->DataExtName->getValue) {
                    $c->nexternalId = $d->DataExtRetList->GetAt($e)->DataExtValue->getValue;
                }
            }
        }

        return $c;
    }


    /**
     * Query for Invoice(s).
     *
     * @param string $txnId     Invoice ID.
     * @param string $refId     Reference ID.
     * @param array  $dateRange Date Range ('to' => int, 'from' => int)
     *
     * @return type
     */
    private function _createInvoiceQuery($txnId=null, $refId=null, $dateRange=null)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__."(".$txnId.",".$refId.",".print_r($dateRange, true).")");

        $query = $this->_qb->request->AppendInvoiceQueryRq();

        if ($txnId) {
            $query->ORInvoiceQuery->InvoiceFilter->InvoiceIDList->setValue($txnId);
        } elseif ($refId) {
            $query->ORInvoiceQuery->InvoiceFilter->ORRefNumberFilter->RefNumberFilter->MatchCriterion->setValue(2);
            $query->ORInvoiceQuery->InvoiceFilter->ORRefNumberFilter->RefNumberFilter->RefNumber->setValue($refId);
        } elseif ($dateRange) {
            $query->ORInvoiceQuery->InvoiceFilter->ORDateRangeFilter->TxnDateRangeFilter->ORTxnDateRangeFilter->TxnDateFilter->FromTxnDate->setValue(date('Y-m-d', $dateRange['from']));
            $query->ORInvoiceQuery->InvoiceFilter->ORDateRangeFilter->TxnDateRangeFilter->ORTxnDateRangeFilter->TxnDateFilter->ToTxnDate->setValue(date('Y-m-d', $dateRange['to']));
        }

        return $this->_qb->sendRequest();
    }


    /**
     * Parse Invoice Query Response.
     *
     * @param type $response
     * @return array Array of Order Objects.
     */
    private function _processInvoiceQueryResponse($response)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);
        $orders = array();

        if (!$response->ResponseList->Count) {
            $this->log->write(Log::ERROR, "Failed to retrieve Invoice List");
            return $orders;
        }
        if (0 != $response->ResponseList->GetAt(0)->StatusCode) {
            $this->log->write(Log::NOTICE, "No Invoices found");
            $this->log->write(Log::NOTICE, "Response From Quickbooks: " . $response->ResponseList->GetAt(0)->StatusMessage);
            return $orders;
        }

        for ($i=0; $i<$response->ResponseList->Count; $i++) {
            $details = &$response->ResponseList->GetAt($i)->Detail;
            for ($n=0; $n<$details->Count; $n++) {
                $d = $details->GetAt($n);
                $o = new Order;
                $o->qbTxn           = $this->_getValue($d,'TxnID');
                $o->id              = $this->_getValue($d,'RefNumber');
                $o->timestamp       = variant_date_to_timestamp($this->_getValue($d,'TxnDate'));
                //$o->type;
                //$o->status;
                $o->subTotal        = $this->_getValue($d,'Subtotal');
                $o->taxTotal        = $this->_getValue($d,'SalesTaxTotal');
                $o->taxRate         = $this->_getValue($d,'SalesTaxPercentage');
                //$o->shipTotal;
                $o->total           = $this->_getValue($d,'TotalAmount');
                $o->memo            = $this->_getValue($d,'Memo');
                $o->location;
                $o->ip;
                $o->paymentStatus;
                $o->paymentMethod;
                if (isset($d->CustomerRef)) {
                    $o->customer    = $this->_getValue($d->CustomerRef,'ListID');
                }
                if (!isset($d->BillAddress) || !isset($d->BillAddress->Addr1)) {
                    Util::sendMail(MAIL_ERRORS, "Invoice Missing Billing Address",
                        sprintf("Invoice ID: %s\n", $o->id));
                    continue;
                }
                $o->billingAddress  = array(
                    'address'   => $this->_getValue($d->BillAddress,'Addr1'),
                    'address2'  => $this->_getValue($d->BillAddress,'Addr2'),
                    'city'      => $this->_getValue($d->BillAddress,'City'),
                    'state'     => $this->_getValue($d->BillAddress,'State'),
                    'zip'       => $this->_getValue($d->BillAddress,'PostalCode'),
                    'country'   => $this->_getValue($d->BillAddress,'Country'),
                    'phone'     => $this->_getValue($d->BillAddress,'Note'),
                );
                $o->shippingAddress = array(
                    'address'   => $this->_getValue($d->ShipAddress,'Addr1'),
                    'address2'  => $this->_getValue($d->ShipAddress,'Addr2'),
                    'city'      => $this->_getValue($d->ShipAddress,'City'),
                    'state'     => $this->_getValue($d->ShipAddress,'State'),
                    'zip'       => $this->_getValue($d->ShipAddress,'PostalCode'),
                    'country'   => $this->_getValue($d->ShipAddress,'Country'),
                    'phone'     => $this->_getValue($d->ShipAddress,'Note'),
                );
                $o->products        = array();
                $o->discounts       = array();
                $o->giftCerts       = array();
                if (isset($d->ORSalesReceiptLineRetList)) {
                    for ($n=0; $n<$d->ORSalesReceiptLineRetList->Count; $n++) {
                        $line = &$d->ORSalesReceiptLineRetList->GetAt($n)->SalesReceiptLineRet;
                        $item = array(
                            'type'      => $this->_getValue($line->ItemRef,'ListID'),
                            'name'      => $this->_getValue($line->ItemRef,'FullName'),
                            'qty'       => $this->_getValue($line->ItemRef,'Quantity'),
                            'price'     => $this->_getValue($line->ItemRef,'Amount'),
                            'tracking'  => $this->_getValue($line->ItemRef,'Other1'),
                        );
                        // @TODO: Product, Discount, or Gift Cert?
                        $o->products[] = $item;
                    }
                }

                $o->nexternalId = $this->_getValue($d,'Other');

                $orders[] = $o;

                // write Orders to File if we've reached our cache cap.
                if (MEMORY_CAP <= memory_get_usage()) {
                    Util::writeCache(QUICKBOOKS_ORDER_CACHE, $orders);
                    $orders = array();
                }
            }
        }
        return $orders;
    }


    /**
     * Query for Sales Receipt(s).
     *
     * @param string $txnId     Sales Receipt ID.
     * @param string $refId     Reference ID.
     * @param array  $dateRange Date Range ('to' => int, 'from' => int)
     *
     * @return type
     */
    private function _createSalesReceiptQuery($txnId=null, $refId=null, $dateRange=null)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__."(".$txnId.",".$refId.",".print_r($dateRange, true).")");

        $query = $this->_qb->request->AppendSalesReceiptQueryRq();

        if ($txnId) {
            $query->ORTxnQuery->TxnFilter->TxnIDList->setValue($txnId);
        } elseif ($refId) {
            $query->ORTxnQuery->TxnFilter->ORRefNumberFilter->RefNumberFilter->MatchCriterion->setValue(2);
            $query->ORTxnQuery->TxnFilter->ORRefNumberFilter->RefNumberFilter->RefNumber->setValue($refId);
        } elseif ($dateRange) {
            $query->ORTxnQuery->TxnFilter->ORDateRangeFilter->TxnDateRangeFilter->ORTxnDateRangeFilter->TxnDateFilter->FromTxnDate->setValue(date('Y-m-d', $dateRange['from']));
            $query->ORTxnQuery->TxnFilter->ORDateRangeFilter->TxnDateRangeFilter->ORTxnDateRangeFilter->TxnDateFilter->ToTxnDate->setValue(date('Y-m-d', $dateRange['to']));
        }

        return $this->_qb->sendRequest();
    }


    /**
     * Parse Sales Receipt Query Response.
     *
     * @param type $response
     * @return array Array of Order Objects.
     */
    private function _processSalesReceiptQueryResponse($response)
    {

        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);
        $orders = array();

        if (!$response->ResponseList->Count) {
            $this->log->write(Log::ERROR, "Failed to retrieve SalesReceipt List");
            return $orders;
        }
        if (0 != $response->ResponseList->GetAt(0)->StatusCode) {
            $this->log->write(Log::NOTICE, "No SalesReceipts found");
            $this->log->write(Log::NOTICE, "Response From Quickbooks: " . $response->ResponseList->GetAt(0)->StatusMessage);
            return $orders;
        }

        for ($i=0; $i<$response->ResponseList->Count; $i++) {
            $details = &$response->ResponseList->GetAt($i)->Detail;
            for ($n=0; $n<$details->Count; $n++) {
                $d = $details->GetAt($n);
                $o = new Order;
                $o->qbTxn           = $this->_getValue($d,'TxnID');
                $o->id              = $this->_getValue($d,'RefNumber');
                $o->timestamp       = variant_date_to_timestamp($this->_getValue($d,'TxnDate'));
                //$o->type;
                //$o->status;
                $o->subTotal        = $this->_getValue($d,'Subtotal');
                $o->taxTotal        = $this->_getValue($d,'SalesTaxTotal');
                $o->taxRate         = $this->_getValue($d,'SalesTaxPercentage');
                //$o->shipTotal;
                $o->total           = $this->_getValue($d,'TotalAmount');
                $o->memo            = $this->_getValue($d,'Memo');
                $o->location;
                $o->ip;
                $o->paymentStatus;
                $o->paymentMethod;
                if (isset($d->CustomerRef)) {
                    $o->customer    = $this->_getValue($d->CustomerRef,'ListID');
                }
                if (!isset($d->BillAddress) || !isset($d->BillAddress->Addr1)) {
                    Util::sendMail(MAIL_ERRORS, "Sales Receipt Missing Billing Address",
                        sprintf("Invoice ID: %s\n", $o->id));
                    continue;
                }
                $o->billingAddress  = array(
                    'address'   => $this->_getValue($d->BillAddress,'Addr1'),
                    'address2'  => $this->_getValue($d->BillAddress,'Addr2'),
                    'city'      => $this->_getValue($d->BillAddress,'City'),
                    'state'     => $this->_getValue($d->BillAddress,'State'),
                    'zip'       => $this->_getValue($d->BillAddress,'PostalCode'),
                    'country'   => $this->_getValue($d->BillAddress,'Country'),
                    'phone'     => $this->_getValue($d->BillAddress,'Note'),
                );
                $o->shippingAddress = array(
                    'address'   => $this->_getValue($d->ShipAddress,'Addr1'),
                    'address2'  => $this->_getValue($d->ShipAddress,'Addr2'),
                    'city'      => $this->_getValue($d->ShipAddress,'City'),
                    'state'     => $this->_getValue($d->ShipAddress,'State'),
                    'zip'       => $this->_getValue($d->ShipAddress,'PostalCode'),
                    'country'   => $this->_getValue($d->ShipAddress,'Country'),
                    'phone'     => $this->_getValue($d->ShipAddress,'Note'),
                );
                $o->products        = array();
                $o->discounts       = array();
                $o->giftCerts       = array();
                if ($d->ORSalesReceiptLineRetList) {
                    for ($n=0; $n<$d->ORSalesReceiptLineRetList->Count; $n++) {
                        $line = &$d->ORSalesReceiptLineRetList->GetAt($n)->SalesReceiptLineRet;
                        $item = array(
                            'type'      => $this->_getValue($line->ItemRef,'ListID'),
                            'name'      => $this->_getValue($line->ItemRef,'FullName'),
                            'qty'       => $this->_getValue($line->ItemRef,'Quantity'),
                            'price'     => $this->_getValue($line->ItemRef,'Amount'),
                            'tracking'  => $this->_getValue($line->ItemRef,'Other1'),
                        );
                        // @TODO: Product, Discount, or Gift Cert?
                        $o->products[] = $item;
                    }
                }

                $o->nexternalId = $this->_getValue($d,'Other');

                $orders[] = $o;

                // write Orders to File if we've reached our cache cap.
                if (MEMORY_CAP <= memory_get_usage()) {
                    Util::writeCache(QUICKBOOKS_ORDER_CACHE, $orders);
                    $orders = array();
                }
            }
        }
        return $orders;
    }


    /**
     * Create a custom feild used to store nexternal ID's
     */
    private function _createCustomField(Customer $customer, $dataExtName, $dataExtValue, $table_name)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);
        $request = $this->_qb->request->AppendDataExtModRq();
        $request->OwnerID->setValue('0');
        $request->DataExtName->setValue($dataExtName);
        $request->DataExtValue->setValue($dataExtValue);
        $request->ORListTxn->ListDataExt->ListDataExtType->SetAsString($table_name);
        $request->ORListTxn->ListDataExt->ListObjRef->FullName->setValue($customer->fullName);
        $response = $this->_qb->sendRequest();

        return (0 != $response->ResponseList->GetAt(0)->StatusCode)
            ? false
            : true;
    }

    /**
     * Create a custom feild used to store nexternal ID's
     */
    private function _createSalesTax($rate, $taxVendorRef="State Board of Equalization")
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);
        $request = $this->_qb->request->AppendItemSalesTaxAddRq();
        $request->Name->setValue($rate.self::TAXCODE_SUFFIX);
        $request->TaxRate->setValue($rate);
        $request->ItemDesc->setValue($rate.self::TAXCODE_SUFFIX." - Tax Rate Generated for Nexternal Sync");
        $request->TaxVendorRef->FullName->setValue($taxVendorRef);
        $resp =  $this->_qb->sendRequest();
        if ($resp->ResponseList->GetAt(0)->StatusCode == 1) {
            return FALSE;
        } elseif ($resp->ResponseList->GetAt(0)->StatusCode == 0) {
            return $rate.self::TAXCODE_SUFFIX;
        }
    }


    /**
     * Checks to see if a tax item exists, if it does return it
     */
    private function _requestTaxItem($code)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);
        if (!preg_match("/^".preg_quote($code.self::TAXCODE_SUFFIX)."$/", $code)) {
            $code .= self::TAXCODE_SUFFIX;
        }
        $request = $this->_qb->request->AppendItemSalesTaxQueryRq();
        $request->ORListQuery->ListFilter->ORNameFilter->NameFilter->MatchCriterion->setValue(0);
        $request->ORListQuery->ListFilter->ORNameFilter->NameFilter->Name->setValue($code);
        $resp =  $this->_qb->sendRequest();
        if($resp->ResponseList->GetAt(0)->StatusCode == 1) {
            return FALSE;
        }
        elseif($resp->ResponseList->GetAt(0)->StatusCode == 0) {
            return $code;
        }
    }


    /**
     * Create New Customer from Customer.
     *
     * @param Customer $customer
     *
     * @return type
     */
    private function _createCustomer(Customer $customer,$order) {

        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);
        $request = $this->_qb->request->AppendCustomerAddRq();

        if ("consumer" == strtolower($customer->type)) {
            $request->Name->setValue                    ($customer->firstName.' '.$customer->lastName);
        } else {
            $request->Name->setValue                    ($customer->company);
        }
        $request->FirstName->setValue                   ($customer->firstName);
        $request->CustomerTypeRef->FullName->setValue   ($customer->type);
        $request->LastName->setValue                    ($customer->lastName);
        $request->Email->setValue                       ($customer->email);
        if(!empty($customer->phone)) {
            $request->Phone->setValue                       ($customer->phone);
        }

        $request->BillAddress->Addr1->setValue          ($order->billingAddress['address']);
        $request->BillAddress->Addr2->setValue          ($order->billingAddress['address2']);
        $request->BillAddress->City->setValue           ($order->billingAddress['city']);
        $request->BillAddress->State->setValue          ($order->billingAddress['state']);
        $request->BillAddress->PostalCode->setValue     ($order->billingAddress['zip']);
        $request->BillAddress->Country->setValue        ($order->billingAddress['country']);
        $request->BillAddress->Note->setValue           ($order->billingAddress['phone']);

        $request->ShipAddress->Addr1->setValue          ($order->shippingAddress['address']);
        $request->ShipAddress->Addr2->setValue          ($order->shippingAddress['address2']);
        $request->ShipAddress->City->setValue           ($order->shippingAddress['city']);
        $request->ShipAddress->State->setValue          ($order->shippingAddress['state']);
        $request->ShipAddress->PostalCode->setValue     ($order->shippingAddress['zip']);
        $request->ShipAddress->Country->setValue        ($order->shippingAddress['country']);
        $request->ShipAddress->Note->setValue           ($order->shippingAddress['phone']);

        // Credit Card.
        if ($order->paymentMethod['type'] == "Credit Card") {
            $this->log->write(Log::WARN, "Creditcard is Masked");
             //cant set because CC is masked
             //$request->CreditCardInfo->CreditCardNumber->SetAsString($order->paymentMethod['cardNumber']);
             //$date_parts = explode("/",$order->paymentMethod['cardNumber']);
             //$request->CreditCardInfo->ExpirationMonth->setValue($date_parts[0]);
             //$request->CreditCardInfo->ExpirationYear->setValue($date_parts[1]);
        }

        return $this->_qb->sendRequest();
    }


    /**
     * Create Sales Receipt from Order.
     * Sales Receipt appended to $this->_qb.
     *
     * @param Order $order
     * @param Customer $customer
     *
     * @return type
     */
    private function _createSalesReceiptFromOrder(Order $order, Customer $customer)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);

        // Query for Sales Tax.
        $code = '';
        if (!empty($order->taxRate)) {
            $code = $this->_requestTaxItem($order->taxRate);
            if ($code == false) {
                $code = $this->_createSalesTax($order->taxRate);
            }
        } else {
            $code = 'Out of State (' . strtoupper(self::TAXCODE_SUFFIX) . ')';
        }

        // Build Request.
        $request = $this->_qb->request->AppendSalesReceiptAddRq();
        $request->ItemSalesTaxRef->FullName->setValue($code);
        $order_date = date ('m/d/Y',$order->timestamp);

        $request->DepositToAccountRef->FullName->setValue('Undeposited Funds');
        $request->RefNumber->setValue(                    "N" . preg_replace("/^N/", "", $order->id));
        $request->TxnDate->setValue(                      $order_date);
        $request->CustomerRef->FullName->setValue(        $customer->fullName);
        if (isset($order->memo) && !empty($order->memo)) {
            $request->Memo->setValue($order->memo);
        }

        // Payment Method.
        if (!empty($order->paymentMethod['type']) && $order->paymentMethod['type'] == "Credit Card") {
            $request->PaymentMethodRef->FullName->setValue($order->paymentMethod['cardType']);
        }

        // Billing Address.
        $request->BillAddress->Addr1->setValue(           $order->billingAddress['address']);
        $request->BillAddress->Addr2->setValue(           $order->billingAddress['address2']);
        $request->BillAddress->City->setValue(            $order->billingAddress['city']);
        $request->BillAddress->State->setValue(           $order->billingAddress['state']);
        $request->BillAddress->PostalCode->setValue(      $order->billingAddress['zip']);
        $request->BillAddress->Country->setValue(         $order->billingAddress['country']);
        if (isset($order->billingAddress['phone'])) {
            $request->BillAddress->Note->setValue(        $order->billingAddress['phone']);
        }

        // Shipping Address.
        if (!empty($order->shippingAddress)) {
            $request->ShipAddress->Addr1->setValue(           $order->shippingAddress['address']);
            $request->ShipAddress->Addr2->setValue(           $order->shippingAddress['address2']);
            $request->ShipAddress->City->setValue(            $order->shippingAddress['city']);
            $request->ShipAddress->State->setValue(           $order->shippingAddress['state']);
            $request->ShipAddress->PostalCode->setValue(      $order->shippingAddress['zip']);
            $request->ShipAddress->Country->setValue(         $order->shippingAddress['country']);
            if (isset($order->shippingAddress['phone'])) {
                $request->ShipAddress->Note->setValue(        $order->shippingAddress['phone']);
            }
        }

        // Products.
        foreach ($order->products as $product) {
            $lineItem = $request->ORSalesReceiptLineAddList->Append();
            $lineItem->SalesReceiptLineAdd->ItemRef->FullName->setValue(           $product['sku']);
            $lineItem->SalesReceiptLineAdd->Desc->setValue(                        $product['name']);
            $lineItem->SalesReceiptLineAdd->Amount->setValue(                      $product['price']);
            $lineItem->SalesReceiptLineAdd->Quantity->setValue(                    $product['qty']);
            $lineItem->SalesReceiptLineAdd->ServiceDate->setValue(                 $order_date);
            $lineItem->SalesReceiptLineAdd->InventorySiteRef->FullName->setValue(  "Main");
        }

        // Gift Certificates.
        foreach ($order->giftCerts as $gc) {
            $lineItem = $request->ORSalesReceiptLineAddList->Append();
            $lineItem->SalesReceiptLineAdd->ItemRef->FullName->setValue(    'Internet Sales');
            $lineItem->SalesReceiptLineAdd->Desc->setValue(                 $gc['code']);
            $lineItem->SalesReceiptLineAdd->Amount->setValue(               $gc['amount']);
        }

        // Discounts.
        foreach ($order->discounts as $discount) {
            $lineItem = $request->ORSalesReceiptLineAddList->Append();
            $lineItem->SalesReceiptLineAdd->ItemRef->FullName->setValue(   'DISCOUNT');
            $lineItem->SalesReceiptLineAdd->Desc->setValue(                $discount['type'].$discount['name']);
            $lineItem->SalesReceiptLineAdd->Amount->setValue(              $discount['value']);
        }

        // Shipping Cost.
        $lineItem = $request->ORSalesReceiptLineAddList->Append();
        $lineItem->SalesReceiptLineAdd->ItemRef->FullName->setValue(      "SHIPPING");
        $lineItem->SalesReceiptLineAdd->Desc->setValue(                   "Shipping & Handling");
        if (!empty($order->shipTotal)) {
            $lineItem->SalesReceiptLineAdd->Amount->setValue(             $order->shipTotal);
        }
        $lineItem->SalesReceiptLineAdd->ServiceDate->setValue(            $order_date);

        if ($order->paymentMethod['type'] == "Credit Card") {
            $this->log->write(Log::WARN, "Creditcard is Masked");
            //card is masked cant add
            //print_r($order->paymentMethod);
            //exit;
        }

        $request->Other->setValue("N" . preg_replace("/^N/", "", $order->id));

        return $this->_qb->sendRequest();
    }


    /**
     * Process Sales Receipt Add Response.
     *
     * @param type $response
     *
     * @return mixed Order object or False on Failure.
     */
    private function _processSalesReceiptAddResponse($response)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);

        if (!$response->ResponseList->Count) {
            $this->log->write(Log::ERROR, "Failed to retrieve SalesReceipt Create Response");
            $this->last_error = "Failed to retrieve SalesReceipt Create Response";
            return false;
        }
        if (0 != $response->ResponseList->GetAt(0)->StatusCode) {
            $this->log->write(Log::NOTICE, "Failed to create Sales Receipt");
            $this->log->write(Log::NOTICE, "Response From Quickbooks: " . $response->ResponseList->GetAt(0)->StatusMessage);
            $this->last_error = $response->ResponseList->GetAt(0)->StatusMessage;
            return false;
        }

        // Detail is not an array, only 1 object is ever returned.
        $d = &$response->ResponseList->GetAt(0)->Detail;
        $o = new Order;
        $o->qbTxn           = $this->_getValue($d,'TxnID');
        $o->id              = $this->_getValue($d,'RefNumber');
        $o->timestamp       = $this->_getValue($d,'TxnDate');
        $o->subTotal        = $this->_getValue($d,'Subtotal');
        $o->taxTotal        = $this->_getValue($d,'SalesTaxTotal');
        $o->taxRate         = $this->_getValue($d,'SalesTaxPercentage');
        $o->total           = $this->_getValue($d,'TotalAmount');
        $o->memo            = $this->_getValue($d,'Memo');
        $o->location;
        $o->ip;
        $o->paymentStatus;
        $o->paymentMethod;
        $o->customer;
        $o->billingAddress  = array(
            'address'   => $this->_getValue($d->BillAddress,'Addr1'),
            'address2'  => $this->_getValue($d->BillAddress,'Addr2'),
            'city'      => $this->_getValue($d->BillAddress,'City'),
            'state'     => $this->_getValue($d->BillAddress,'State'),
            'zip'       => $this->_getValue($d->BillAddress,'PostalCode'),
            'country'   => $this->_getValue($d->BillAddress,'Country'),
        );
        $o->shippingAddress = array(
            'address'   => $this->_getValue($d->ShipAddress,'Addr1'),
            'address2'  => $this->_getValue($d->ShipAddress,'Addr2'),
            'city'      => $this->_getValue($d->ShipAddress,'City'),
            'state'     => $this->_getValue($d->ShipAddress,'State'),
            'zip'       => $this->_getValue($d->ShipAddress,'PostalCode'),
            'country'   => $this->_getValue($d->ShipAddress,'Country'),
        );
        $o->products        = array();
        $o->discounts       = array();
        $o->giftCerts       = array();
        $o->nexternalId = $this->_getValue($d,'Other');

        for ($n=0; $n<$d->ORSalesReceiptLineRetList->Count; $n++) {
            $line = &$d->ORSalesReceiptLineRetList->GetAt($n)->SalesReceiptLineRet;
            $item = array(
                'type'      => $this->_getValue($line->ItemRef,'ListID'),
                'name'      => $this->_getValue($line->ItemRef,'FullName'),
                'qty'       => $this->_getValue($line->ItemRef,'Quantity'),
                'price'     => $this->_getValue($line->ItemRef,'Amount'),
                'tracking'  => $this->_getValue($line->ItemRef,'Other1'),
            );
            // @TODO: Product, Discount, or Gift Cert?
            $o->products[] = $item;
        }

        return $o;
    }


    /**
     * Update Sales Receipt REF Number.
     *
     * @param type $txn
     * @param Order $order
     *
     * @return type
     */
    private function _createSalesReceiptUpdate($txn, Order $order)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__."(".$txn.")");

        $request = $this->_qb->request->AppendSalesReceiptModRq();

        $request->IncludeRetElementList->add('DataExtRet');
        $request->OwnerIDList->setValue(0);

        $request->TxnID->setValue($txn);
        $request->RefNumber->setValue("N" . preg_replace("/^N/", "", $order->id));

        $nexternalId = $request->DataExtRet->Append();
        $nexternalId->DataExtName->setValue("NexternalId");
        $nexternalId->DataExtValue->setValue("N" . preg_replace("/^N/", "", $order->id));

        return $this->_qb->sendRequest();
    }


    /**
     * Process Sales Receipt Update Response
     *
     * @param type $response
     * @return mixed Sales Receipt RefID or False.
     */
    private function _processSalesReceiptUpdateResponse($response)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);

        if (!$response->ResponseList->Count) {
            $this->log->write(Log::ERROR, "Failed to retrieve SalesReceipt Update Response.");
            return false;
        }
        if (0 != $response->ResponseList->GetAt(0)->StatusCode) {
            $this->log->write(Log::NOTICE, "Error updating Sales Receipt");
            $this->log->write(Log::NOTICE, "Response From Quickbooks: " . $response->ResponseList->GetAt(0)->StatusMessage);
            return false;
        }

        return $response->ResponseList->GetAt(0)->Detail->RefNumber->getValue;
    }


    /**
     *
     */
    private function _getValue(&$object, $attribute)
    {
        if (!is_object($object)) {
            $this->log->write(Log::WARN, "Invalid Object specified for Attribute: " . $attribute);
        }
        if (property_exists($object, $attribute) && is_object($object->$attribute)) {
            return $object->$attribute->getValue;
        }
        return '';
    }

}
