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
    const GIFTCERT_NAME  = "Gift Certificate";
    const DISCOUNT_NAME  = "DISCOUNT";
    const SHIPPING_NAME  = "SHIPPING";
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
            $this->log->write(Log::NOTICE, sprintf("Could not find Customer by ID[%s].", $id));
            return false;
        }
        return array_shift($customer);
    }


     /**
     * Get create custom field for customer
     */
    public function createCustomCustomerField($customerId, $dataExtName, $dataExtValue, $table_name)
    {
        $response = $this->_createCustomFieldUpdate($customerId, $dataExtName, $dataExtValue, $table_name);

        if (0 != $response->ResponseList->GetAt(0)->StatusCode) {
            $this->log->write(Log::WARN, "Failed to set NexternalID on Quickbooks Customer: " . $customerId);
            $this->log->write(Log::WARN, "Response From Quickbooks: " . $response->ResponseList->GetAt(0)->StatusMessage);
            return $response->ResponseList->GetAt(0)->StatusMessage;
        }
        return true;
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


    public function getInventory($site)
    {
        return $this->_processInventoryQueryResponse(
            $this->_createInventoryQuery($site)
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
                    $c->phone       = $this->_getValue($d,'Phone');
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
        $query->IncludeLineItems->setValue(true);

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

        $this->log->write(Log::INFO, "Invoices Found: " . $response->ResponseList->Count);
        for ($i=0; $i<$response->ResponseList->Count; $i++) {
            $details = &$response->ResponseList->GetAt($i)->Detail;
            for ($n=0; $n<$details->Count; $n++) {
                $d = $details->GetAt($n);
                $o = new Order;
                $o->qbTxn           = $this->_getValue($d,'TxnID');
                $o->id              = $this->_getValue($d,'RefNumber');
                $o->timestamp       = variant_date_to_timestamp($this->_getValue($d,'TxnDate'));
                $o->shipDate        = $o->timestamp;
                //$o->type;
                //$o->status;
                $o->subTotal        = $this->_getValue($d,'Subtotal');
                $o->taxTotal        = $this->_getValue($d,'SalesTaxTotal');
                $o->taxRate         = $this->_getValue($d,'SalesTaxPercentage');
                //$o->shipTotal;
                $o->total           = $o->subTotal;
                $o->memo            = $this->_getValue($d,'Memo');
                $o->location;
                $o->ip;
                $o->paymentStatus   = Order::PAYMENTSTATUS_PAID;
                $o->paymentMethod   = Order::PAYMENTMETHOD_INVOICE;
                if (isset($d->CustomerRef)) {
                    $o->customer    = $this->_getValue($d->CustomerRef,'ListID');
                }
                if (!isset($d->BillAddress) || !is_object($d->BillAddress) || !isset($d->BillAddress->Addr1)) {
                    $this->log->mail("[INVOICE ".$o->id."] Ignored because Billing Address is missing.", Log::CATEGORY_QB_ORDER);
                    continue 2;
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
                if (isset($d->ShipAddress) && is_object($d->ShipAddress) && isset($d->ShipAddress->Addr1)) {
                    $o->shippingAddress = array(
                        'address'   => $this->_getValue($d->ShipAddress,'Addr1'),
                        'address2'  => $this->_getValue($d->ShipAddress,'Addr2'),
                        'city'      => $this->_getValue($d->ShipAddress,'City'),
                        'state'     => $this->_getValue($d->ShipAddress,'State'),
                        'zip'       => $this->_getValue($d->ShipAddress,'PostalCode'),
                        'country'   => $this->_getValue($d->ShipAddress,'Country'),
                        'phone'     => $this->_getValue($d->ShipAddress,'Note'),
                    );
                }
                $o->products        = array();
                $o->discounts       = array();
                $o->giftCerts       = array();
                $o->shipping        = array();
                if (isset($d->ORInvoiceLineRetList) && !is_null($d->ORInvoiceLineRetList)) {
                    for ($x=0; $x<$d->ORInvoiceLineRetList->Count; $x++) {
                        $line = &$d->ORInvoiceLineRetList->GetAt($x)->InvoiceLineRet;
                        $item = array(
                            'type'      => $this->_getValue($line->ItemRef,'ListID'),
                            'sku'       => $this->_getValue($line->ItemRef,'FullName'),
                            'name'      => $this->_getValue($line,'Desc'),
                            'qty'       => (int)$this->_getValue($line,'Quantity'),
                            'price'     => floatval($this->_getValue($line,'Amount')),
                        );
                        // Make sure we have a SKU.
                        if (empty($item['sku'])) {
                            continue;
                        }
                        // Product, Discount, Gift Cert, or Shipping?
                        switch (strtoupper($item['sku'])) {
                            case self::DISCOUNT_NAME:
                                array_push($o->discounts, $item);
                                break;
                            case self::GIFTCERT_NAME:
                                array_push($o->giftCerts, $item);
                                break;
                            case self::SHIPPING_NAME:
                                array_push($o->shipping, $item);
                                break;
                            default:
                                array_push($o->products, $item);
                                break;
                        }
                    }
                }

                $o->nexternalId = $this->_getValue($d,'Other');

                if (empty($o->products)) {
                    $this->log->write(LOG::WARN, sprintf("[INVOICE %s] IGNORED -- No Products Found!", $o->id));
                } else {
                    $orders[] = $o;
                }

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
        $query->IncludeLineItems->setValue(true);

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
                $o->shipDate        = $o->timestamp;
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
                if (!isset($d->BillAddress) || !is_object($d->BillAddress) || !isset($d->BillAddress->Addr1)) {
                    $this->log->mail("[SALES RECEIPT ".$o->id."] Ignored because Billing Address is missing.", Log::CATEGORY_QB_ORDER);
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
                            'sku'       => $this->_getValue($line->ItemRef,'FullName'),
                            'name'      => $this->_getValue($line,'Desc'),
                            'qty'       => $this->_getValue($line,'Quantity'),
                            'price'     => $this->_getValue($line,'Amount'),
                        );
                        // Product, Discount, Gift Cert, or Shipping?
                        switch (strtoupper($item['name'])) {
                            case self::DISCOUNT_NAME:
                                array_push($o->discounts, $item);
                                break;
                            case self::GIFTCERT_NAME:
                                array_push($o->giftCerts, $item);
                                break;
                            //case self::SHIPPING_NAME:
                                //array_push($o->shipping, $item);
                                //break;
                            default:
                                array_push($o->products, $item);
                                break;
                        }
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
     * Create a custom field used to store nexternal ID's
     */
    private function _createCustomFieldUpdate($listId, $dataExtName, $dataExtValue, $table_name)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);
        $request = $this->_qb->request->AppendDataExtModRq();
        $request->OwnerID->setValue('0');
        $request->DataExtName->setValue($dataExtName);
        $request->DataExtValue->setValue($dataExtValue);
        $request->ORListTxn->ListDataExt->ListDataExtType->SetAsString($table_name);
        $request->ORListTxn->ListDataExt->ListObjRef->ListID->setValue($listId);

        return $this->_qb->sendRequest();
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
        $resp = $this->_qb->sendRequest();
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

        // Query for Sales Tax.
        $code = '';
        $itemCode = "Tax";
        if (!empty($order->taxRate)) {
            $code = $this->_requestTaxItem($order->taxRate);
            if ($code == false) {
                $code = $this->_createSalesTax($order->taxRate);
            }
        } else {
            $code = 'Out of State (' . strtoupper(self::TAXCODE_SUFFIX) . ')';
            $itemCode = "No";
        }

        // Build Customer
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
        if (!empty($customer->phone)) {
            $request->Phone->setValue                   ($customer->phone);
        }

        // Billing Address.
        $a = $this->_makeAddress($order->billingAddress);
        foreach ($a as $k => $v) {
            if (!empty($v)) {
                $request->BillAddress->$k->setValue($v);
            }
        }

        // Shipping Address.
        $a = $this->_makeAddress($order->shippingAddress);
        foreach ($a as $k => $v) {
            if (!empty($v)) {
                $request->ShipAddress->$k->setValue($v);
            }
        }

        // Tax Code.
        $request->ItemSalesTaxRef->FullName->setValue($code);

        // Credit Card.
        if ($order->paymentMethod['type'] == "Credit Card") {
            $this->log->write(Log::WARN, "Creditcard is Masked");
            // @TODO: May need to handle masked CCs differently.
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
        $itemCode = "Tax";
        if (!empty($order->taxRate)) {
            $code = $this->_requestTaxItem($order->taxRate);
            if ($code == false) {
                $code = $this->_createSalesTax($order->taxRate);
            }
        } else {
            $code = 'Out of State (' . strtoupper(self::TAXCODE_SUFFIX) . ')';
            $itemCode = "No";
        }

        // Build Request.
        $request = $this->_qb->request->AppendSalesReceiptAddRq();
        $request->ItemSalesTaxRef->FullName->setValue($code);

        // @NOTICE: Order Date in QB needs to be the date the order shipped.
        $order_date = date ('m/d/Y',$order->shipDate);

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
        $a = $this->_makeAddress($order->billingAddress);
        foreach ($a as $k => $v) {
            if (!empty($v)) {
                $request->BillAddress->$k->setValue($v);
            }
        }

        // Shipping Address.
        $a = $this->_makeAddress($order->shippingAddress);
        foreach ($a as $k => $v) {
            if (!empty($v)) {
                $request->ShipAddress->$k->setValue($v);
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
            if ("Gift Certificate" != $product['sku']) {
                $lineItem->SalesReceiptLineAdd->InventorySiteRef->FullName->setValue("Main");
            }
            if ($order->type == "Gift Certificate") {
                $lineItem->SalesReceiptLineAdd->SalesTaxCodeRef->FullName->setValue("0");
            } else {
                $lineItem->SalesReceiptLineAdd->SalesTaxCodeRef->FullName->setValue($itemCode);
            }
        }

        // Gift Certificates.
        foreach ($order->giftCerts as $gc) {
            $lineItem = $request->ORSalesReceiptLineAddList->Append();
            $lineItem->SalesReceiptLineAdd->ItemRef->FullName->setValue(    self::GIFTCERT_NAME);
            $lineItem->SalesReceiptLineAdd->Desc->setValue(                 $gc['code']);
            $lineItem->SalesReceiptLineAdd->Amount->setValue(               $gc['amount']);
            $lineItem->SalesReceiptLineAdd->SalesTaxCodeRef->FullName->setValue("No");
        }

        // Discounts.
        foreach ($order->discounts as $discount) {
            $lineItem = $request->ORSalesReceiptLineAddList->Append();
            $lineItem->SalesReceiptLineAdd->ItemRef->FullName->setValue(   self::DISCOUNT_NAME);
            $lineItem->SalesReceiptLineAdd->Desc->setValue(                implode(" ", array($discount['type'],$discount['name'])));
            $lineItem->SalesReceiptLineAdd->Amount->setValue(              -1 *abs($discount['value']));
            $lineItem->SalesReceiptLineAdd->SalesTaxCodeRef->FullName->setValue("Tax");
        }

        // Shipping Cost.
        $lineItem = $request->ORSalesReceiptLineAddList->Append();
        $lineItem->SalesReceiptLineAdd->ItemRef->FullName->setValue(      self::SHIPPING_NAME);
        $lineItem->SalesReceiptLineAdd->Desc->setValue(                   "Shipping & Handling");
        $lineItem->SalesReceiptLineAdd->SalesTaxCodeRef->FullName->setValue("No");
        if (!empty($order->shipTotal)) {
            $lineItem->SalesReceiptLineAdd->Amount->setValue(             $order->shipTotal);
        }
        $lineItem->SalesReceiptLineAdd->ServiceDate->setValue(            $order_date);

        if ($order->paymentMethod['type'] == "Credit Card") {
            $this->log->write(Log::WARN, "Creditcard is Masked");
            // @TODO: May need to handle masked CCs differently.
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
        $o->shipDate        = $o->timestamp;
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


    private function _createInventoryQuery($site)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);

        $query = $this->_qb->request->AppendItemSitesQueryRq();

        $query->ActiveStatus->SetAsString("ActiveOnly");
        $query->IncludeRetElementList->add("QuantityOnHand");
        $query->ORItemSitesQuery->ItemSitesFilter->ORItemSitesFilter->ItemSiteFilter->SiteFilter->ORSiteFilter->FullNameList->add($site);

        return $this->_qb->sendRequest();
    }


    private function _processInventoryQueryResponse($response)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);

        if (!$response->ResponseList->Count) {
            $this->log->write(Log::ERROR, "Failed to retrieve InvetoryQuery Response.");
            return false;
        }
        if (0 != $response->ResponseList->GetAt(0)->StatusCode) {
            $this->log->write(Log::NOTICE, "Error retrieving Inventory");
            $this->log->write(Log::NOTICE, "Response From Quicbooks: " . $response->ResponseList->GetAt(0)->StatusMessage);
            return false;
        }

        $items = array();
        for ($i=0; $i<$response->ResponseList->GetAt(0)->Detail->Count; $i++) {
            $details = $response->ResponseList->GetAt(0)->Detail->GetAt($i);
            $item = $details->ORItemAssemblyORInventory->ItemInventoryRef;
            $iDetails = array(
                'sku' => $this->_getValue($item, 'FullName'),
                'qty' => (int) $this->_getValue($details, 'QuantityOnHand')
            );
            if (strlen($iDetails['sku']) > 0) {
                array_push($items, $iDetails);
            }
        }

        return $items;
    }


    private function _makeAddress($address)
    {
        $return = array(
            'Addr1'     => null,
            'Addr2'     => null,
            'Addr3'     => null,
            'Addr4'     => null,
            'City'      => $address['city'],
            'State'     => $address['state'],
            'PostalCode'=> $address['zip'],
            'Country'   => $address['country'],
            'Note'      => null,
        );

        // Is there a name and company?
        if (!empty($address['company']) && (!empty($address['firstName']) || !empty($address['lastName']))) {
            $company = $this->_trimCompanyName($address['company'], 41);
            $name    = $this->_trimName($address['firstName'], $address['lastName'], 41);
            // Do we have to combine them?
            if (empty($return['Addr2'])) {
                // No.
                $return['Addr1'] = $name;
                $return['Addr2'] = $company;
            } else {
                // Yes, we have to combine them.
                $test = implode(" - ", array($company, $name));
                if (strlen($test) <= 41) {
                    $return['Addr1'] = $test;
                }
                $test = implode(" - ", array($company,
                    $this->_trimName($address['firstName'], $address['lastName'], 41-strlen($company)))
                );
                if (strlen($test) <= 41) {
                    $return['Addr1'] = $test;
                }
                // All else fails, chop em both up...
                $tname = $this->_trimName($address['firstName'], $address['lastName'], 40-strlen($company));
                $tcomp = $this->_trimCompanyName($address['company'], 39-strlen($tname));
                $return['Addr1'] = substr(implode(" - ", array($tcomp, $tname)),0,41);
            }
        } elseif (!empty($address['company'])) {
            $return['Addr1'] = $this->_trimCompanyName($address['company'], 41);
        } elseif (!empty($address['firstName']) || !empty($address['lastName'])) {
            $return['Addr1'] = $this->_trimName($address['firstName'], $address['lastName'], 41);
        }

        // Is there a second address field?
        if (!empty($address['address2'])) {
            $return['Addr2'] = $address['address'];
            $return['Addr3'] = $address['address2'];
        } else {
            if (!empty($return['Addr2'])) {
                $return['Addr3'] = $address['address'];
            } else {
                $return['Addr2'] = $address['address'];
            }
        }
        return $return;
    }


    private function _trimCompanyName($company, $len)
    {
        trim($company);

        // Simple solution first, try as is.
        if (strlen($company) <= $len) {
            return $company;
        }

        // Check for a suffix.
        if (preg_match("/^(.+), (.+).?$/", $company, $m)) {
            // Try without the suffix.
            if (strlen($m[1]) <= $len) {
                return $m[1];
            }
        }

        // Just trim it.
        return substr($company, 0, $len);
    }


    /**
     * Trim Name
     *
     * @param string $first
     * @param string $last
     * @param int $len
     * @return string
     */
    private function _trimName($first, $last, $len)
    {
        $return = "";
        trim($first);
        trim($last);
        ucfirst($first);
        ucfirst($last);

        if (!empty($first) && !empty($last)) {
            // Simple solution first... join them and return.
            $return = $first . " " . $last;
            if (strlen($return) <= $len) {
                return $return;
            }
            // Use Initial for first name.
            $return = substr($first,0,1) . ". " . $last;
            if (strlen($return) <= $len) {
                return $return;
            }
            // Use just last Name.
            if (strlen($last) <= $len) {
                return $last;
            }
            // Use Initials.
            return substr($first,0,1).". ".substr($last,0,1).".";
        }
        elseif (!empty($last)) {
            $return = $last;
        }
        elseif (!empty($first)) {
            $return = $first;
        }
        // Is our return string to long?
        if (strlen($return) > $len) {
            // Just trim it...
            return substr($return,0,$len);
        }
        return $return;
    }


    /**
     * Get the Value of an Object Attribute.
     *
     * @param object $object
     * @param string $attribute
     *
     * @return string
     */
    private function _getValue(&$object, $attribute)
    {
        if (!is_object($object)) {
            $this->log->write(Log::WARN, "Invalid Object specified for Attribute: " . $attribute);
        } elseif (property_exists($object, $attribute) && is_object($object->$attribute)) {
            return $object->$attribute->getValue;
        }
        return '';
    }

}
