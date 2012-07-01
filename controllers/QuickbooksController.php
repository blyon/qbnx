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
    private $_qb;
    public $log;


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
        return $customer[0];
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
        return $this->_processSalesReceiptAddResponse(
            $this->_createSalesReceiptFromOrder($order, $customer)
        );
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
        $query->IncludeRetElementList->add('DataExtRet');
        $query->OwnerIDList->add(0);
        $query->ORCustomerListQuery->ListIDList->add($listId);
        return $this->_qb->sendRequest();
    }


    private function _processCustomerQueryResponse($response)
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
                $c = new Customer;
                $c->company     = $this->_getValue($d,'CompanyName');
                $c->type        = $this->_getValue($d->CustomerTypeRef,'FillName');
                $c->email       = $this->_getValue($d,'Email');
                $c->firstName   = $this->_getValue($d,'FirstName');
                $c->lastName    = $this->_getValue($d,'LastName');
                if ($d->DataExtRetList) {
                    for ($e=0; $e<$d->DataExtRetList->Count; $e++) {
                        if ("NexternalId" == $d->DataExtRetList->GetAt($e)->DataExtName->getValue) {
                            $c->nexternalId = $d->DataExtRetList->GetAt($e)->DataExtValue->getValue;
                        }
                    }
                }
                $customers[] = $c;

                // write Customers to File if we've reached our cache cap.
                if (MEMORY_CAP <= memory_get_usage()) {
                    Util::writeCache(QUICKBOOKS_CUSTOMER_CACHE, $customers);
                    $customers = array();
                }
            }
        }

        return $customers;
    }


    /**
     * Query for a specific Sales Receipt.
     *
     * @param string $id Sales Receipt ID.
     * @return type
     */
    private function _createSalesReceiptQuery($txnId=null, $refId=null, $dateRange=null)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__."(".$txnId.",".$refId.",".implode(":", $dateRange).")");

        $query = $this->_qb->request->AppendSalesReceiptQueryRq();
        if ($txnId) {
            $query->ORTxnQuery->TxnFilter->TxnIDList->setValue($txnId);
        } elseif ($refId) {
            $query->ORTxnQuery->TxnFilter->RefNumberList->setValue($refId);
        } elseif ($dateRange) {
            $query->ORTxnQuery->TxnFilter->ORDateRangeFilter->TxnDateRangeFilter->ORTxnDateRangeFilter->TxnDateFilter->FromTxnDate->setValue(date('Y-m-d', $dateRange['from']));
            $query->ORTxnQuery->TxnFilter->ORDateRangeFilter->TxnDateRangeFilter->ORTxnDateRangeFilter->TxnDateFilter->ToTxnDate->setValue(date('Y-m-d', $dateRange['to']));
        }
        // 0-Starts With, 1-Contains, 2-Ends With
        //$query->ORTxnQuery->TxnFilter->ORRefNumberFilter->RefNumberFilter->MatchCriterion->setValue(2);
        //$query->ORTxnQuery->TxnFilter->OrRefNumberFilter->RefNumberFilter->RefNumber->setValue($id);

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
                if ($d->DataExtRet) {
                    for ($e=0; $e<$d->DataExtRet->Count; $e++) {
                        if ("NexternalId" == $d->DataExtRet->GetAt($e)->DataExtName->getValue) {
                            $o->nexternalId = $d->DataExtRet->GetAt($e)->DataExtValue->getValue;
                        }
                    }
                }
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
        $request = $this->_qb->request->AppendSalesReceiptAddRq();

        // General Sales Receipt Info.
        $request->DepositToAccountRef->FullName->setValue( $customer->fullName());
        $request->RefNumber->setValue(                    "N" . preg_replace("/^N/", "", $order->id));
        $request->TxnDate->setValue(                      $order->date());
        $request->CustomerRef->FullName->setValue(        $customer->fullName());
        $request->Memo->setValue(                         $order->memo());
        // Payment Method.
        $request->PaymentMethodRef->FullName->setValue(   $order->cardType());
        // Sales Tax.
        $request->ItemSalesTaxRef->FullName->setValue(    $order->taxName());
        // Billing Address.
        $request->BillAddress->Addr1->setValue(           $order->billingAddress());
        $request->BillAddress->Addr2->setValue(           $order->billingAddress2());
        $request->BillAddress->City->setValue(            $order->billingCity());
        $request->BillAddress->State->setValue(           $order->billingState());
        $request->BillAddress->PostalCode ->setValue(     $order->billingZip());
        $request->BillAddress->Country->setValue(         $order->billingCountry());
        $request->BillAddress->Note->setValue(            $order->billingNote());
        // Shipping Address.
        $request->ShipAddress->Addr1->setValue(           $order->shippingAddress());
        $request->ShipAddress->Addr2->setValue(           $order->shippingAddress2());
        $request->ShipAddress->City->setValue(            $order->shippingCity());
        $request->ShipAddress->State->setValue(           $order->shippingState());
        $request->ShipAddress->PostalCode->setValue(      $order->shippingZip());
        $request->ShipAddress->Country->setValue(         $order->shippingCountry());
        $request->ShipAddress->Note->setValue(            $order->shippingNote());
        // Products.
        foreach ($order->products as $product) {
            $lineItem = $request->ORSalesReceiptLineAddList->Append();
            $lineItem->SalesReceiptLineAdd->ItemRef->FullName->setValue(           $product->name());
            $lineItem->SalesReceiptLineAdd->Desc->setValue(                        $product->description());
            $lineItem->SalesReceiptLineAdd->Amount->setValue(                      $product->total());
            $lineItem->SalesReceiptLineAdd->Quantity->setValue(                    $product->quantity());
            $lineItem->SalesReceiptLineAdd->ServiceDate->setValue(                 $order->date());
            $lineItem->SalesReceiptLineAdd->InventorySiteRef->FullName->setValue(  "Main");
        }
        // Gift Certificates.
        foreach ($order->giftCerts as $gc) {
            $lineItem = $request->ORSalesReceiptLineAddList->Append();
            $lineItem->SalesReceiptLineAdd->ItemRef->FullName->setValue(   $gc->name());
            $lineItem->SalesReceiptLineAdd->Desc->setValue(                $gc->description());
            $lineItem->SalesReceiptLineAdd->Amount->setValue(              $gc->amount());
        }
        // Discounts.
        foreach ($order->discounts as $discount) {
            $lineItem = $request->ORSalesReceiptLineAddList->Append();
            $lineItem->SalesReceiptLineAdd->ItemRef->FullName->setValue(   $discount->name());
            $lineItem->SalesReceiptLineAdd->Desc->setValue(                $discount->description());
            $lineItem->SalesReceiptLineAdd->Amount->setValue(              $discount->total());
        }
        // Shipping Cost.
        $lineItem = $request->ORSalesReceiptLineAddList->Append();
        $lineItem->SalesReceiptLineAdd->ItemRef->FullName->setValue(   "SHIPPING");
        $lineItem->SalesReceiptLineAdd->Desc->setValue(                "Shipping & Handling");
        $lineItem->SalesReceiptLineAdd->Amount->setValue(              $order->shipTotal());
        $lineItem->SalesReceiptLineAdd->ServiceDate->setValue(         $order->date());

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
            return false;
        }
        if (0 != $response->ResponseList->GetAt(0)->StatusCode) {
            $this->log->write(Log::NOTICE, "Failed to create Sales Receipt");
            $this->log->write(Log::NOTICE, "Response From Quickbooks: " . $response->ResponseList->GetAt(0)->StatusMessage);
            return false;
        }

        $d = &$response->ResponseList->GetAt($i)->Detail;
        $o = new Order;
        $o->qbTxn           = $this->_getValue($d,'TxnID');
        $o->id              = $this->_getValue($d,'RefNumber');
        $o->timestamp       = $this->_getValue($d,'TxnDate');
        //$o->type;
        //$o->status;
        $o->subTotal        = $this->_getValue($d,'Subtotal');
        $o->taxTotal        = $this->_getValue($d,'SalesTaxTotal');
        //$o->shipTotal;
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
        if ($d->DataExtRet) {
            for ($e=0; $e<$d->DataExtRet->Count; $e++) {
                if ("NexternalId" == $d->DataExtRet->GetAt($e)->DataExtName->getValue) {
                    $o->nexternalId = $d->DataExtRet->GetAt($e)->DataExtValue->getValue;
                }
            }
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
        if (property_exists($object, $attribute) && is_object($object->$attribute)) {
            return $object->$attribute->getValue;
        }
        return '';
    }
}
