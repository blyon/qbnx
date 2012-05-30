<?php
require_once('Log.php');
require_once('Util.php');

class Quickbooks
{
    private $_sm;
    private $_qbfcVersion;
    private $_request;
    private $_ticket;
    private $_config;
    private $_docroot;
    private $log;
    public $appName;


    /**
     * Intialize config, docroot, and log in Constructor.
     */
    public function __construct()
    {
        $this->_docroot = preg_replace("@/$@", "", dirname(dirname(__FILE__))) . "/";
        $config = Util::config();
        $this->_config = $config['Nexternal'];
        $this->log = Log::getInstance();
        $this->log->directory = $config['Log']['directory'];

        $this->connect();
    }


    public function __destruct()
    {
        $this->_sm->EndSession($this->_ticket);
        $this->_sm->CloseConnection();
    }


    private function sendRequest()
    {
        // Send Request.
        $response = $this->_sm->DoRequests($this->_req);

        // Clear Requests.
        $this->_req->ClearRequests();

        // Ensure Response.
        if (!$response->ResponseList->Count) {
            $this->log->write(Log::WARN, "Request returned 0 records");
        }

        return $response.
    }


    public function connect()
    {
        $this->log->write(Log::DEBUG, "Quickbooks::connect");

        // Create Session Manager.
        $this->_sm = new COM("QBFC" . $this->_qbfcVersion . ".QBSessionManager");
        // Open Connection.
        $this->_sm->OpenConnection("", $this->appName);
        // Begin Session (ignore multi/single user modes)
        $this->_sm->BeginSession("", 2);
        // Set Message Request.
        $this->_request = $this->_sm->CreateMsgSetRequest("US", $this->_qbfcVersion, 0);
        // Allow "continue on error"
        $this->_request->Attributes->OnError = 1;
    }


    public function querySalesReceipt($invoiceNumber)
    {
        $this->log->write(Log::DEBUG, "Quickbooks::querySalesReceipt(".$invoiceNumber.")");

        $query = $self->_req->AppendSalesReceiptQueryRq();
        // 0-Starts With, 1-Contains, 2-Ends With
        $query->ORTxnQuery->TxnFilter->ORRefNumberFilter->RefNumberFilter->MatchCriterion->setValue(2);
        $query->OrTxnQuery->TxnFilter->OrRefNumberFilter->RefNumber->setValue($invoiceNumber);

        return $this->sendRequest();
    }


    public function processSalesReceiptQuery($invoiceNumber, $response)
    {
        $this->log->write(Log::DEBUG, "Quickbooks::processSalesReceiptQuery(".$invoiceNumber.")");

        if (!$response->ResponseList->Count) {
            $this->log->write(Log::ERROR, "Failed to retrieve SalesReceipt List for invoice: " . $invoiceNumber);
            return false;
        }
        if (preg_match("/did not find/", $response->ResponseList->GetAt(0)->StatusMessage) {

            $this->log->write(Log::NOTICE, "No SalesReceipts for invoice: " . $invoiceNumber);
            return false;
        }
        return true;
    }


    public function addSalesReceipt()
    {
    }
}

