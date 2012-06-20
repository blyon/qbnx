<?php
require_once dirname(__FILE__) . '/Log.php';
require_once dirname(__FILE__) . '/../includes/Util.php';
require_once dirname(__FILE__) . '/Order.php';
require_once dirname(__FILE__) . '/Customer.php';

class Nexternal
{
    const POST_URL              = 'https://www.nexternal.com/shared/xml/';
    const POST_HEADERS          = "Content-type: application/x-www-form-urlencoded\r\n";
    const BILLSTAT_UNBILLED     = 'Unbilled';
    const BILLSTAT_AUTHORIZED   = 'Authorized';
    const BILLSTAT_BILLED       = 'Billed';
    const BILLSTAT_PARTIALBILL  = 'Billed-Partial';
    const BILLSTAT_PAID         = 'Paid';
    const BILLSTAT_PARTIALPAID  = 'Paid-Partial';
    const BILLSTAT_REFUNDED     = 'Refunded';
    const BILLSTAT_PARTIALREFUND= 'Refunded-Partial';
    const BILLSTAT_DECLINED     = 'Declined';
    const BILLSTAT_CC           = 'CC';
    const BILLSTAT_CANCELED     = 'Canceled';
    const AUTHSTEP_INACTIVE     = 'inactive';
    const AUTHSTEP_PENDING      = 'pending';
    const AUTHSTEP_ACTIVE       = 'active';
    const KEYTYPE_NODE          = 'Node';
    const KEYTYPE_ATTRIBUTE     = 'Attribute';
    const CUSTUPDATE_MAX        = 15;
    const ORDERUPDATE_MAX       = 15;

    private static $__instance;
    /**
     * @var string Filesystem Root Path.
     */
    private $_docroot   = "";
    /**
     * @var array Nexternal Configuration.
     */
    private $_config;
    /**
     * @var string Authentication Key.
     */
    public $key;
    /**
     * @var string Location of Authentication Key.
     */
    public $keyType;
    /**
     * @var string Credentials Key for Authenticated Requests.
     */
    public $activeKey;
    /**
     * @var string Authentication Sent.
     * Used to determine how to format Credentials.
     */
    public $authStep = self::AUTHSTEP_INACTIVE;
    /**
     * @var object Log.
     */
    public $log;
    /**
     * @var SimpleXml object sent to Nexternal.
     */
    public $dom;


    /**
     * Get Singleton Instance.
     *
     * @return Quickbooks.
     */
    public static function getInstance()
    {
        if (self::$__instance === null) {
            self::$__instance = new Nexternal;
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
        Util::deleteCache(NEXTERNAL_ORDER_CACHE);
        Util::deleteCache(NEXTERNAL_CUSTOMER_CACHE);

        $this->_docroot         = preg_replace("@/$@", "", dirname(dirname(__FILE__))) . "/";
        $config                 = Util::config();
        $this->_config          = $config['Nexternal'];
        $this->log              = Log::getInstance();
        $this->log->directory   = $config['Log']['directory'];
    }


    public function __destruct()
    {
        Util::deleteCache(NEXTERNAL_ORDER_CACHE);
        Util::deleteCache(NEXTERNAL_CUSTOMER_CACHE);
    }


    /**
     * Initialize dom object and add Authentication Credentials.
     * @see self::_addCredentials
     */
    public function initDom($xml)
    {
        // Initialize DOM with specified $xml.
        $this->dom = new SimpleXMLElement($xml);
        $this->_addCredentials();
    }


    /**
     * Add Authentication Credentials to Dom.
     */
    private function _addCredentials()
    {
        $this->dom->addChild('Credentials')->addChild('AccountName', $this->_config['account']);
        // Authenticated -> Verify
        if (self::AUTHSTEP_PENDING == $this->authStep) {
            $this->dom->Credentials->addChild('UserName', $this->_config['username']);
            $this->dom->Credentials->addChild('Password', $this->_config['password']);
            if (empty($this->key) || empty($this->keyType)) {
                throw new Exception("Cannot generate credentials for Authenticated Request, missing key or keyType");
            }
            if ($this->keyType == self::KEYTYPE_NODE) {
                $this->dom->Credentials->addChild('Key', $this->key);
            } elseif ($this->keyType == self::KEYTYPE_ATTRIBUTE) {
                $this->dom->Credentials->addAttribute('Key', $this->key);
            } else {
                throw new Exception("Cannot generate credentials for Authenticated Request, invalid keyType: " . $this->keyType);
            }
        // Verified.
        } elseif (self::AUTHSTEP_ACTIVE == $this->authStep) {
            if (empty($this->activeKey)) {
                throw new Exception("Cannot generate credentials for Active Request, missing key");
            }
            $this->dom->Credentials->addChild('Key', $this->activeKey);
        // Not Authenticated.
        } else {
            $this->dom->Credentials->addChild('UserName', $this->_config['username']);
            $this->dom->Credentials->addChild('Password', $this->_config['password']);
        }
    }


    /**
     * Send current DOM Object to Nexternal $page.
     *
     * @param string $page
     *
     * @return mixed SimpleXml Object or FALSE on error.
     */
    public function sendDom($page)
    {
        $xml = $this->dom->asXml();
        $this->log->write(Log::INFO, "Sent Message");
        $this->log->write(Log::INFO, $xml);

        // Send XML to Nexternal.
        $response = Util::postRequest(self::POST_URL . $page, $xml, self::POST_HEADERS);

        $this->log->write(Log::INFO, "Received Response");
        $this->log->write(Log::INFO, $response);

        // Parse Response.
        $responseDom = simplexml_load_string($response);

        // Check for Error.
        foreach ($responseDom->children() as $child) {
            if ($child->getName() == 'Error') {
                $this->log->write(Log::ERROR, $child->ErrorDescription);
                return false;
            }
        }

        return $responseDom;
    }

}
