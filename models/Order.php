<?php

class Order
{
    const PAYMENTMETHOD_INVOICE = "Invoice";
    const PAYMENTSTATUS_PAID    = "Paid";

    public $id;
    public $nexternalId;
    public $timestamp;
    public $type;
    public $status;
    public $subTotal;
    public $taxTotal;
    public $taxRate;
    public $shipTotal;
    public $total;
    public $memo;
    public $location;
    public $ip;
    public $paymentStatus;
    public $paymentMethod;
    public $customer;
    public $billingAddress;
    public $shippingAddress;
    public $qbTxn;
    public $products        = array();
    public $discounts       = array();
    public $giftCerts       = array();
}

