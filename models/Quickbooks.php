<?php
require_once dirname(__FILE__) . '/Log.php';
require_once dirname(__FILE__) . '/Util.php';
require_once dirname(__FILE__) . '/Order.php';
require_once dirname(__FILE__) . '/Customer.php';

class Quickbooks
{
    private $__instance;
    private $_sm;
    private $_qbfcVersion;
    private $_request;
    private $_ticket;
    private $_docroot;
    private $_appName;
    public  $log;


    public static function getInstance()
    {
        if (self::$__instance === null) {
            self::$__instance = new Quickbooks;
            self::initConfig();
        }
        return self::$__instance;
    }


    private function __construct() {}
    private function __clone() {}


    /**
     * Intialize config, docroot, and log in Constructor.
     */
    private function initConfig()
    {
        self::$_docroot         = preg_replace("@/$@", "", dirname(dirname(__FILE__))) . "/";
        $config                 = Util::config();
        self::$_appName         = $config['Quickbooks']['app'];
        self::$_qbfcVersion     = $config['Quickbooks']['version'];
        self::$log              = Log::getInstance();
        self::$log->directory   = $config['Log']['directory'];

        self::connect();
    }


    /**
     * Safely end session and close connection.
     */
    public function __destruct()
    {
        self::$_sm->EndSession(self::$_ticket);
        self::$_sm->CloseConnection();
    }


    /**
     *
     *
     * @return type
     */
    private function sendRequest()
    {
        // Send Request.
        $response = self::$_sm->DoRequests(self::$_request);

        // Clear Requests.
        self::$_request->ClearRequests();

        // Ensure Response.
        if (!$response->ResponseList->Count) {
            self::$log->write(Log::WARN, "Request returned 0 records");
        }

        return $response;
    }


    /**
     *
     */
    public function connect()
    {
        self::$log->write(Log::DEBUG, "Quickbooks::connect");

        // Create Session Manager.
        self::$_sm = new COM("QBFC" . self::$_qbfcVersion . ".QBSessionManager");
        // Open Connection.
        self::$_sm->OpenConnection("", self::$_appName);
        // Begin Session (ignore multi/single user modes)
        self::$_sm->BeginSession("", 2);
        // Set Message Request.
        self::$_request = self::$_sm->CreateMsgSetRequest("US", self::$_qbfcVersion, 0);
        // Allow "continue on error"
        self::$_request->Attributes->OnError = 1;
    }


    /**
     *
     * @param type $invoiceNumber
     * @return type
     */
    public function querySalesReceipt($invoiceNumber)
    {
        self::$log->write(Log::DEBUG, "Quickbooks::querySalesReceipt(".$invoiceNumber.")");

        $query = $self->_request->AppendSalesReceiptQueryRq();
        // 0-Starts With, 1-Contains, 2-Ends With
        $query->ORTxnQuery->TxnFilter->ORRefNumberFilter->RefNumberFilter->MatchCriterion->setValue(2);
        $query->OrTxnQuery->TxnFilter->OrRefNumberFilter->RefNumber->setValue($invoiceNumber);

        return self::sendRequest();
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
        self::$log->write(Log::DEBUG, "Quickbooks::processSalesReceiptQuery(".$invoiceNumber.")");

        if (!$response->ResponseList->Count) {
            self::$log->write(Log::ERROR, "Failed to retrieve SalesReceipt List for invoice: " . $invoiceNumber);
            return false;
        }
        if (preg_match("/did not find/", $response->ResponseList->GetAt(0)->StatusMessage)) {
            self::$log->write(Log::NOTICE, "No SalesReceipts for invoice: " . $invoiceNumber);
            return false;
        }
        return true;
    }


    public function addSalesReceipt(Order $order)
    {
        $request = self::$_request->AppendSalesReceiptAddRq();

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

    }


    public function addSalesTaxItem() {

    }


    public function processAddSalesTaxItem($response) {

    }
}

