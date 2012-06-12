<?php
require_once dirname(__FILE__) . '/Log.php';
require_once dirname(__FILE__) . '/Util.php';
require_once dirname(__FILE__) . '/Order.php';
require_once dirname(__FILE__) . '/Customer.php';

class Quickbooks
{
    private static $__instance;
    private static $_sm;
    private static $_qbfcVersion;
    private static $_ticket;
    private static $_docroot;
    private static $_appName;
    public  static $request;
    public  static $log;


    /**
     * Get Singleton Instance.
     *
     * @return
     */
    public static function getInstance()
    {
        if (self::$__instance === null) {
            self::$__instance = new Quickbooks;
        }
        return self::$__instance;
    }


    /**
     * Prevent Cloning.
     */
    private function __clone() {}


    /**
     * Intialize config, docroot, and log in Constructor.
     */
    private function __construct()
    {
        $this->_docroot         = preg_replace("@/$@", "", dirname(dirname(__FILE__))) . "/";
        $config                 = Util::config();
        $this->_appName         = $config['Quickbooks']['app'];
        $this->_qbfcVersion     = $config['Quickbooks']['version'];
        $this->log              = Log::getInstance();
        $this->log->directory   = $config['Log']['directory'];

        $this->connect();
    }


    /**
     * Safely end session and close connection.
     */
    public function __destruct()
    {
        $this->_sm->EndSession(self::$_ticket);
        $this->_sm->CloseConnection();
    }


    /**
     *
     */
    public function connect()
    {
        $this->log->write(Log::DEBUG, "Quickbooks::connect");

        // Create Session Manager.
        $this->_sm = new COM("QBFC" . self::$_qbfcVersion . ".QBSessionManager");
        // Open Connection.
        $this->_sm->OpenConnection("", self::$_appName);
        // Begin Session (ignore multi/single user modes)
        $this->_sm->BeginSession("", 2);
        // Set Message Request.
        $this->request = self::$_sm->CreateMsgSetRequest("US", $this->_qbfcVersion, 0);
        // Allow "continue on error"
        $this->request->Attributes->OnError = 1;
    }


    /**
     *
     */
    public static function sendRequest()
    {
        // Send Request.
        $response = $this->_sm->DoRequests($this->request);

        // Clear Requests.
        $this->request->ClearRequests();

        // Ensure Response.
        if (!$response->ResponseList->Count) {
            $this->log->write(Log::WARN, "Request returned 0 records");
        }

        return $response;
    }
}
