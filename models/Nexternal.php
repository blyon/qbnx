<?php
require_once dirname(__FILE__) . '/Log.php';
require_once dirname(__FILE__) . '/Util.php';
require_once dirname(__FILE__) . '/Order.php';
require_once dirname(__FILE__) . '/Customer.php';

class Nexternal
{
    /**
     * @var string Nexternal Post Url.
     */
    const POST_URL              = 'https://www.nexternal.com/shared/xml/';
    /**
     * @var string Nexternal Post Headers.
     */
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
    private $_key;
    /**
     * @var string Location of Authentication Key. (Node/Attribute)
     */
    private $_keyType;
    /**
     * @var string Credentials Key for Authenticated Requests.
     */
    private $_activeKey;
    /**
     * @var string Authentication Sent. ('', 'auth', 'active')
     * Used to determine how to format Credentials.
     */
    private $_authStep  = '';
    /**
     * @var object Log.
     */
    private $log;

    /**
     * @var SimpleXml object sent to Nexternal.
     */
    public $dom;


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
    }


    /**
     * Initialize dom object and add Authentication Credentials.
     * @see self::_addCredentials
     */
    private function _initDom($xml)
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
        if ('auth' == $this->_authStep) {
            $this->dom->Credentials->addChild('UserName', $this->_config['username']);
            $this->dom->Credentials->addChild('Password', $this->_config['password']);
            if (empty($this->_key) || empty($this->_keyType))
                throw new Exception("Cannot generate credentials for Authenticated Request, missing key or keyType");
            if ($this->_keyType == 'Node')
                $this->dom->Credentials->addChild('Key', $this->_key);
            elseif ($this->_keyType == 'Attribute')
                $this->dom->Credentials->addAttribute('Key', $this->_key);
            else
                throw new Exception("Cannot generate credentials for Authenticated Request, invalid keyType: " . $this->_keyType);
        // Verified.
        } elseif ('active' == $this->_authStep) {
            if (empty($this->_activeKey))
                throw new Exception("Cannot generate credentials for Active Request, missing key");
            $this->dom->Credentials->addChild('Key', $this->_activeKey);
        // Not Authenticated.
        } else {
            $this->dom->Credentials->addChild('UserName', $this->_config['username']);
            $this->dom->Credentials->addChild('Password', $this->_config['password']);
        }
    }


    /**
     * Send current DOM Object to Nexternal $page.
     *
     * @param string    $page
     *
     * @return mixed    SimpleXml Object or FALSE on error.
     */
    private function _sendDom($page)
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


    /**
     * Send Authentication Request to Nexternal.
     *
     * @return SimpleXML object.
     */
    public function sendAuthentication()
    {
        $this->log->write(Log::DEBUG, "Nexternal::sendAuthentication");

        // Initialize DOM {@see _addCredentials}.
        $this->_initDom('<TestSubmitRequest/>');

        // Send XML to Nexternal.
        $responseDom = $this->_sendDom('testsubmit.rest');

        // Check for Error.
        if (false == $responseDom) {
            $this->log->write(Log::ERROR, "Authentication Failed");
            throw new Exception("Authentication Failed");
        }

        return $responseDom;
    }


    /**
     * Process Authentication Response.
     * Set Auth Key and KeyType from Authentication Response.
     *
     * @param SimpleXml Object  $responseDom
     *
     * @return boolean
     */
    public function processAuthenticationResponse($responseDom)
    {
        $this->log->write(Log::DEBUG, "Nexternal::processAuthenticationResponse");

        // Get Key Type.
        foreach ($responseDom->attributes() as $attribute => $value) {
            if ($attribute == 'Type') {
                $this->_keyType = $value;
                $this->log->write(Log::NOTICE, "Authentication Key Type set to: " . $this->_keyType);
                break;
            }
        }

        // Get Key.
        $this->_key = $responseDom->TestKey;
        $this->log->write(Log::NOTICE, "Authentication Key set to: " . $this->_key);

        // Update _authStep.
        $this->_authStep = 'auth';

        // Return boolean if both key and keyType are set.
        return (!empty($this->_key) && !empty($this->_keyType));
    }


    /**
     * Send Authentication Verification.
     *
     * @return SimpleXml Object
     */
    public function sendVerification()
    {
        $this->log->write(Log::DEBUG, "Nexternal::authenticationVerify");

        // Initialize DOM {@see _addCredentials}.
        $this->_initDom('<TestVerifyRequest/>');

        // Send XML to Nexternal.
        $responseDom = $this->_sendDom('testverify.rest');

        // Check for Error.
        if (false == $responseDom) {
            $this->log->write(Log::ERROR, "Authentication Verification Failed");
            throw new Exception("Authentication Verification Failed");
        }

        return $responseDom;
    }


    /**
     * Process Verification Response.
     * Set Authenticatoin Active Key.
     *
     * @param SimpleXml Object  $responseDom
     *
     * @return boolean
     */
    public function processVerificationResponse($responseDom)
    {
        $this->log->write(Log::DEBUG, "Nexternal::processVerificationResponse");

        // Update _authStep.
        $this->_authStep = 'active';

        // Update key.
        $this->_activeKey = $responseDom->ActiveKey;

        // Return boolean if activeKey is set.
        return (!empty($this->_activeKey));
    }


    /**
     * Query Nexternal for Orders.
     *
     * @param integer   $startDate
     * @param integer   $endDate
     * @param string    $billingStatus
     * @param integer   $page
     *
     * @return SimpleXml Object response.
     */
    public function orderQuery($startDate, $endDate, $billingStatus=null, $page=1)
    {
        $this->log->write(Log::DEBUG, "Nexternal::orderQuery(".$startDate.",".$endDate.",".$billingStatus.",".$page.")");

        // Initialize DOM {@see _addCredentials}.
        $this->_initDom('<OrderQueryRequest/>');

        // Add Date Range Filter.
        $this->dom->addChild('OrderUpdRange')->
            addChild('OrderUpdStart')->
            addChild('DateTime')->
            addChild('Date', date('m/d/Y', $startDate));
        $this->dom->OrderUpdRange->OrderUpdStart->DateTime->addChild('Time', date('H:i', $startDate));
        $this->dom->OrderUpdRange->
            addChild('OrderUpdEnd')->
            addChild('DateTime')->
            addChild('Date', date('m/d/Y', $endDate));
        $this->dom->OrderUpdRange->OrderUpdEnd->DateTime->addChild('Time', date('H:i', $endDate));

        // Add Billing Status Filter.
        if (!is_null($billingStatus)) {
            if (in_array($billingStatus, array(self::BILLSTAT_UNBILLED, self::BILLSTAT_AUTHORIZED,
                self::BILLSTAT_BILLED, self::BILLSTAT_PARTIALBILL, self::BILLSTAT_PAID,
                self::BILLSTAT_PARTIALPAID, self::BILLSTAT_REFUNDED, self::BILLSTAT_PARTIALREFUND,
                self::BILLSTAT_DECLINED, self::BILLSTAT_CC, self::BILLSTAT_CANCELED,
            ))) {
                $this->dom->addChild('BillingStatus', $billingStatus);
            } else {
                $this->log->write(Log::CRIT, "Unable to send Order Query, invalid Billing Status: " . $billingStatus);
                throw new Exception("Unable to send Order Query, invalid Billing Status: " . $billingStatus);
            }
        }

        // Add Page Filter.
        $this->dom->addChild('Page', $page);

        // Send XML to Nexternal.
        $responseDom = $this->_sendDom('orderquery.rest');

        // Return Response Dom.
        return $responseDom;
    }


    /**
     * Process Order Query Response.
     *
     * @param SimpleXml Object
     */
    public function processOrderQueryResponse($dom)
    {
        $return = array(
            'morePages' => false,
            'orders'    => array(),
            'errors'    => array(),
        );

        // Process Errors.
        // @TODO.

        // Check for More Pages.
        $return['morePages'] = isset($dom->NextPage);

        // Process Orders.
        if (isset($dom->Order)) {
            foreach ($dom->Order as $order) {
                $o = new Order;
                $o->id              = (string) $order->OrderNo;
                $o->timestamp       = strtotime((string) $order->OrderDate->DateTime->Date . ' '
                    . (string) $order->OrderDate->DateTime->Time);
                $o->type            = (string) $order->OrderType;
                $o->status          = (string) $order->OrderStatus;
                $o->subTotal        = (string) $order->OrderNet;
                $o->taxTotal        = (string) $order->SalesTax->SalesTaxTotal;
                $o->shipTotal       = (string) $order->ShipRate;
                $o->total           = (string) $order->OrderAmount;
                $o->memo            = (string) $order->Comments->CompanyComments;
                $o->location        = (string) $order->PlacedBy;
                $o->ip              = (string) $order->IP->IPAddress;
                $o->paymentStatus   = (string) $order->BillingStatus;
                $o->paymentMethod['type'] = (string) $order->Payment->PaymentMethod;
                if ($o->paymentMethod['type'] == 'Credit Card') {
                    $o->paymentMethod['cardType']  = (string) $order->Payment->CreditCard->CreditCardType;
                    $o->paymentMethod['cardNumber']= (string) $order->Payment->CreditCard->CreditCardNumber;
                    $o->paymentMethod['cardExp']   = (string) $order->Payment->CreditCard->CreditCardExpDate;
                }
                $o->customer        = (string) $order->Customer->CustomerNo;
                $o->billingAddress  = array(
                    'name'     => (string) $order->BillTo->Address->Name->FirstName
                        . ' ' . (string) $order->BillTo->Address->Name->LastName,
                    'company'  => (string) $order->BillTo->Address->CompanyName,
                    'address'  => (string) $order->BillTo->Address->StreetAddress1,
                    'address2' => (string) $order->BillTo->Address->StreetAddress2,
                    'city'     => (string) $order->BillTo->Address->City,
                    'state'    => (string) $order->BillTo->Address->StateProvCode,
                    'zip'      => (string) $order->BillTo->Address->ZipPostalCode,
                    'country'  => (string) $order->BillTo->Address->CountryCode,
                    'phone'    => (string) $order->BillTo->Address->PhoneNumber,
                );
                $o->shippingAddress  = array(
                    'name'     => (string) $order->ShipTo->Address->Name->FirstName
                        . ' ' . (string) $order->ShipTo->Address->Name->LastName,
                    'company'  => (string) $order->ShipTo->Address->CompanyName,
                    'address'  => (string) $order->ShipTo->Address->StreetAddress1,
                    'address2' => (string) $order->ShipTo->Address->StreetAddress2,
                    'city'     => (string) $order->ShipTo->Address->City,
                    'state'    => (string) $order->ShipTo->Address->StateProvCode,
                    'zip'      => (string) $order->ShipTo->Address->ZipPostalCode,
                    'country'  => (string) $order->ShipTo->Address->CountryCode,
                    'phone'    => (string) $order->ShipTo->Address->PhoneNumber,
                );

                // Add Product(s).
                if (isset($order->ShipTo->ShipFrom->LineItem)) {
                    foreach ($order->ShipTo->ShipFrom->LineItem as $lineItem) {
                        $o->products[] = array(
                            'sku'       => (string) $lineItem->LineProduct->ProductSKU,
                            'name'      => (string) $lineItem->LineProduct->ProductName,
                            'qty'       => (string) $lineItem->Quantity,
                            'price'     => (string) $lineItem->ExtPrice,
                            'tracking'  => (string) $lineItem->TrackingNumber,
                        );
                    }
                }

                // Add Discount(s).
                if (isset($order->Discount)) {
                    foreach ($order->Discount as $discount) {
                        $o->discounts[] = array(
                            'type'  => (string) $discount->Type,
                            'name'  => (string) $discount->Name,
                            'value' => (string) $discount->Value,
                        );
                    }
                }

                // Add Gift Certificate(s).
                if (isset($order->GiftCert)) {
                    foreach ($order->GiftCert as $gc) {
                        $o->giftCerts[] = array(
                            'code'   => (string) $gc->GiftCertCode,
                            'amount' => (string) $gc->GiftCertAmount,
                        );
                    }
                }

                $return['orders'][] = $o;
            }
        }

        return $return;
    }


    /**
     * Query Nexternal for Customers.
     *
     * @param integer   $startDate
     * @param integer   $endDate
     * @param integer   $page
     *
     * @return SimpleXml Object
     */
    public function customerQuery($startDate, $endDate, $page=1)
    {
        $this->log->write(Log::DEBUG, "Nexternal::customerQuery(".$startDate.",".$endDate.",".$page.")");

        // Initialize DOM {@see _addCredentials}.
        $this->_initDom('<CustomerQueryRequest/>');

        // Add Date Range Filter.
        $this->dom->addChild('CustomerUpdRange')->
            addChild('CustomerUpdStart')->
            addChild('DateTime')->
            addChild('Date', date('m/d/Y', $startDate));
        $this->dom->CustomerUpdRange->CustomerUpdStart->DateTime->addChild('Time', date('H:i', $startDate));
        $this->dom->CustomerUpdRange->
            addChild('CustomerUpdEnd')->
            addChild('DateTime')->
            addChild('Date', date('m/d/Y', $startDate));
        $this->dom->CustomerUpdRange->CustomerUpdEnd->DateTime->addChild('Time', date('H:i', $endDate));

        // Add Page Filter.
        $this->dom->addChild('Page', $page);

        // Send XML to Nexternal.
        $responseDom = $this->_sendDom('customerquery.rest');

        // Return Response Dom.
        return $responseDom;
    }


    /**
     *
     *
     *
     */
    public function processCustomerQueryResponse($dom)
    {
        $return = array(
            'morePages' => false,
            'customers' => array(),
            'errors'    => array(),
        );

        // Process Errors.
        // @TODO.

        // Check for More Pages.
        $return['morePages'] = isset($dom->NextPage);

        // Process Customers.
        if (isset($dom->Customer)) {
            foreach ($dom->Customer as $customer) {
                $c = new Customer;
                $c->id      = (string) $customer->CustomerNo;
                $c->email   = (string) $customer->Email;
                $c->type    = (string) $customer->CustomerType;
                $c->address  = array(
                    'type'     => (string) $customer->Address->Type,
                    'name'     => (string) $customer->Address->Name->FirstName
                        . ' ' . (string) $customer->Address->Name->LastName,
                    'company'  => (string) $order->Address->CompanyName,
                    'address'  => (string) $order->Address->StreetAddress1,
                    'address2' => (string) $order->Address->StreetAddress2,
                    'city'     => (string) $order->Address->City,
                    'state'    => (string) $order->Address->StateProvCode,
                    'zip'      => (string) $order->Address->ZipPostalCode,
                    'country'  => (string) $order->Address->CountryCode,
                    'phone'    => (string) $order->Address->PhoneNumber,
                );

                $return['customers'][] = $c;
            }
        }

        return $return;
    }

}
