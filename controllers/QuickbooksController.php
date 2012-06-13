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
     * Query for a specific Sales Receipt.
     *
     * @param string $id Sales Receipt ID.
     * @return type
     */
    public function querySalesReceipt($id)
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
     * @return boolean
     */
    public function processSalesReceiptQuery($invoiceNumber, $response)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__."(".$invoiceNumber.")");

        if (!$response->ResponseList->Count) {
            $this->log->write(Log::ERROR, "Failed to retrieve SalesReceipt List for invoice: " . $invoiceNumber);
            return false;
        }
        if (preg_match("/did not find/", $response->ResponseList->GetAt(0)->StatusMessage)) {
            $this->log->write(Log::NOTICE, "No SalesReceipts for invoice: " . $invoiceNumber);
            return false;
        }
        return true;
    }


    public function addSalesReceipt(Order $order)
    {
        $this->log->write(Log::DEBUG, __CLASS__."::".__FUNCTION__);
        $request = $this->_qb->request->AppendSalesReceiptAddRq();

        // General Sales Receipt Info.
        $request->DepositToAccountRef->FullName;
        $request->RefNumber;
        $request->TxnDate;
        $request->CustomerRef->FullName;
        $request->Memo;
        // Payment Method.
        $request->PaymentMethodRef->FullName;
        // Sales Tax.
        $request->ItemSalesTaxRef->FullName;
        // Billing Address.
        $request->BillAddress->Addr1;
        $request->BillAddress->Addr2;
        $request->BillAddress->City;
        $request->BillAddress->State;
        $request->BillAddress->PostalCode;
        $request->BillAddress->Country;
        $request->BillAddress->Note;
        // Shipping Address.
        $request->ShipAddress->Addr1;
        $request->ShipAddress->Addr2;
        $request->ShipAddress->City;
        $request->ShipAddress->State;
        $request->ShipAddress->PostalCode;
        $request->ShipAddress->Country;
        $request->ShipAddress->Note;
        // Products.
        foreach ($order->products as $product) {
            $lineItem = $request->ORSalesReceiptLineAddList->Append();
            $lineItem->SalesReceiptLineAdd->ItemRef->FullName;
            $lineItem->SalesReceiptLineAdd->Desc;
            $lineItem->SalesReceiptLineAdd->Amount;
            $lineItem->SalesReceiptLineAdd->Quantity;
            $lineItem->SalesReceiptLineAdd->ServiceDate;
        }
        // Gift Certificates.
        foreach ($order->giftCerts as $gc) {
            $lineItem = $request->ORSalesReceiptLineAddList->Append();
            $lineItem->SalesReceiptLineAdd->ItemRef->FullName;
            $lineItem->SalesReceiptLineAdd->Desc;
            $lineItem->SalesReceiptLineAdd->Amount;
        }
        // Discounts.
        foreach ($order->discounts as $discount) {
            $lineItem = $request->ORSalesReceiptLineAddList->Append();
            $lineItem->SalesReceiptLineAdd->ItemRef->FullName;
            $lineItem->SalesReceiptLineAdd->Desc;
            $lineItem->SalesReceiptLineAdd->Amount;
        }
        // Shipping Cost.
        $lineItem = $request->ORSalesReceiptLineAddList->Append();
        $lineItem->SalesReceiptLineAdd->ItemRef->FullName;
        $lineItem->SalesReceiptLineAdd->Desc;
        $lineItem->SalesReceiptLineAdd->Amount;
        $lineItem->SalesReceiptLineAdd->ServiceDate;

        // Send Request to Quickbooks.
        return self::sendRequest();
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
