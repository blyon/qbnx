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
     */
    public static function connect()
    {
        self::$log->write(Log::DEBUG, "Quickbooks::connect");

        // Create Session Manager.
        self::$_sm = new COM("QBFC" . self::$_qbfcVersion . ".QBSessionManager");
        // Open Connection.
        self::$_sm->OpenConnection("", self::$_appName);
        // Begin Session (ignore multi/single user modes)
        self::$_sm->BeginSession("", 2);
        // Set Message Request.
        self::$request = self::$_sm->CreateMsgSetRequest("US", self::$_qbfcVersion, 0);
        // Allow "continue on error"
        self::$request->Attributes->OnError = 1;
    }


    /**
     *
     */
    public static function sendRequest()
    {
        // Send Request.
        $response = self::$_sm->DoRequests(self::$request);

        // Clear Requests.
        self::$request->ClearRequests();

        // Ensure Response.
        if (!$response->ResponseList->Count) {
            self::$log->write(Log::WARN, "Request returned 0 records");
        }

        return $response;
    }
}
