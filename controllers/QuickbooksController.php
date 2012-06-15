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


    public function getSalesReceipt($id)
    {
        $this->_createSalesReceiptQuery($id);
        $this->_processSalesReceiptQueryResponse($id, $this->_qb->sendRequest());
    }


    public function addSalesReceipt($order, $customer)
    {
        $this->_createSalesReceiptFromOrder($order, $customer);
        $this->_processSalesReceiptAddResponse($this->_qb->sendRequest());
    }


    /**
     * Query for a specific Sales Receipt.
     *
     * @param string $id Sales Receipt ID.
     * @return type
     */
    public function _createSalesReceiptQuery($id)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__."(".$id.")");

        $query = $this->_qb->request->AppendSalesReceiptQueryRq();
        // 0-Starts With, 1-Contains, 2-Ends With
        $query->ORTxnQuery->TxnFilter->ORRefNumberFilter->RefNumberFilter->MatchCriterion->setValue(2);
        $query->ORTxnQuery->TxnFilter->OrRefNumberFilter->RefNumberFilter->RefNumber->setValue($id);

        return $this->_qb->sendRequest();
    }


    /**
     * @TODO: Refactor to return Order object.
     *
     * @param type $invoiceNumber
     * @param type $response
     * @return array Array of Order Objects.
     */
    public function _processSalesReceiptQueryResponse($invoiceNumber, $response)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__."(".$invoiceNumber.")");
        $orders = array();

        if (!$response->ResponseList->Count) {
            $this->log->write(Log::ERROR, "Failed to retrieve SalesReceipt List for invoice: " . $invoiceNumber);
            return $orders;
        }
        if (0 != $response->ResponseList->GetAt(0)->StatusCode) {
            $this->log->write(Log::NOTICE, "No SalesReceipts for invoice: " . $invoiceNumber);
            $this->log->write(Log::NOTICE, "Response From Quickbooks: " . $response->ResponseList->GetAt(0)->StatusMessage);
            return $orders;
        }

        for ($i=0; $i<$response->ResponseList->Count; $i++) {
            $d = $response->ResponseList->GetAt($i)->Detail;
            $o = new Order;
            $o->id              = $d->RefNumber->getValue;
            $o->timestamp       = $d->TxnDate->getValue;
            //$o->type;
            //$o->status;
            $o->subTotal        = $d->Subtotal->getValue;
            $o->taxTotal        = $d->SalesTaxTotal->getValue;
            //$o->shipTotal;
            $o->total           = $d->TotalAmount->getValue;
            $o->memo            = $d->Memo->getValue;
            $o->location;
            $o->ip;
            $o->paymentStatus;
            $o->paymentMethod;
            $o->customer;
            $o->billingAddress  = array(
                'address'   => $d->BillAddress->Addr1->getValue,
                'address2'  => $d->BillAddress->Addr2->getValue,
                'city'      => $d->BillAddress->City->getValue,
                'state'     => $d->BillAddress->State->getValue,
                'zip'       => $d->BillAddress->PostalCode->getValue,
                'country'   => $d->BillAddress->Country->getValue,
            );
            $o->shippingAddress = array(
                'address'   => $d->ShipAddress->Addr1->getValue,
                'address2'  => $d->ShipAddress->Addr2->getValue,
                'city'      => $d->ShipAddress->City->getValue,
                'state'     => $d->ShipAddress->State->getValue,
                'zip'       => $d->ShipAddress->PostalCode->getValue,
                'country'   => $d->ShipAddress->Country->getValue,
            );
            $o->products        = array();
            $o->discounts       = array();
            $o->giftCerts       = array();
            for ($n=0; $n<$d->ORSalesReceiptLineRetList->Count; $n++) {
                $line = $d->ORSalesReceiptLineRetList->GetAt($n)->SalesReceiptLineRet;
                $item = array(
                    'type'      => $line->ItemRef->ListID->getValue,
                    'name'      => $line->ItemRef->FullName->getValue,
                    'qty'       => $line->ItemRef->Quantity->getValue,
                    'price'     => $line->ItemRef->Amount->getValue,
                    'tracking'  => $line->ItemRef->Other1,
                );
                // @TODO: Product, Discount, or Gift Cert?
                $o->products[] = $item;
            }

        }
        return true;
    }


    /**
     * Create Sales Receipt from Order.
     * Sales Receipt appended to $this->_qb.
     *
     * @param Order $order
     * @param Customer $customer
     *
     * @return void
     */
    public function _createSalesReceiptFromOrder(Order $order, Customer $customer)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);
        $request = $this->_qb->request->AppendSalesReceiptAddRq();

        // General Sales Receipt Info.
        $request->DepositToAccountRef->FullName = $customer->fullName();
        $request->RefNumber                     = $order->id;
        $request->TxnDate                       = $order->date();
        $request->CustomerRef->FullName         = $customer->fullName();
        $request->Memo                          = $order->memo();
        // Payment Method.
        $request->PaymentMethodRef->FullName    = $order->cardType();
        // Sales Tax.
        $request->ItemSalesTaxRef->FullName     = $order->taxName();
        // Billing Address.
        $request->BillAddress->Addr1            = $order->billingAddress();
        $request->BillAddress->Addr2            = $order->billingAddress2();
        $request->BillAddress->City             = $order->billingCity();
        $request->BillAddress->State            = $order->billingState();
        $request->BillAddress->PostalCode       = $order->billingZip();
        $request->BillAddress->Country          = $order->billingCountry();
        $request->BillAddress->Note             = $order->billingNote();
        // Shipping Address.
        $request->ShipAddress->Addr1            = $order->shippingAddress();
        $request->ShipAddress->Addr2            = $order->shippingAddress2();
        $request->ShipAddress->City             = $order->shippingCity();
        $request->ShipAddress->State            = $order->shippingState();
        $request->ShipAddress->PostalCode       = $order->shippingZip();
        $request->ShipAddress->Country          = $order->shippingCountry();
        $request->ShipAddress->Note             = $order->shippingNote();
        // Products.
        foreach ($order->products as $product) {
            $lineItem = $request->ORSalesReceiptLineAddList->Append();
            $lineItem->SalesReceiptLineAdd->ItemRef->FullName   = $product->name();
            $lineItem->SalesReceiptLineAdd->Desc                = $product->description();
            $lineItem->SalesReceiptLineAdd->Amount              = $product->total();
            $lineItem->SalesReceiptLineAdd->Quantity            = $product->quantity();
            $lineItem->SalesReceiptLineAdd->ServiceDate         = $order->date();
        }
        // Gift Certificates.
        foreach ($order->giftCerts as $gc) {
            $lineItem = $request->ORSalesReceiptLineAddList->Append();
            $lineItem->SalesReceiptLineAdd->ItemRef->FullName   = $gc->name();
            $lineItem->SalesReceiptLineAdd->Desc                = $gc->description();
            $lineItem->SalesReceiptLineAdd->Amount              = $gc->amount();
        }
        // Discounts.
        foreach ($order->discounts as $discount) {
            $lineItem = $request->ORSalesReceiptLineAddList->Append();
            $lineItem->SalesReceiptLineAdd->ItemRef->FullName   = $discount->name();
            $lineItem->SalesReceiptLineAdd->Desc                = $discount->description();
            $lineItem->SalesReceiptLineAdd->Amount              = $discount->total();
        }
        // Shipping Cost.
        $lineItem = $request->ORSalesReceiptLineAddList->Append();
        $lineItem->SalesReceiptLineAdd->ItemRef->FullName   = "SHIPPING";
        $lineItem->SalesReceiptLineAdd->Desc                = "Shipping & Handling";
        $lineItem->SalesReceiptLineAdd->Amount              = $order->shipTotal();
        $lineItem->SalesReceiptLineAdd->ServiceDate         = $order->date();
    }


    /**
     *
     * @param type $response
     */
    public function processAddSalesReceipt($response) {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);

    }


    public function addSalesTaxItem() {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);

    }


    public function processAddSalesTaxItem($response) {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);

    }
}
