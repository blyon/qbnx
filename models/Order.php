<?php

class Order
{
    public $id;
    public $nexternalId;
    public $timestamp;
    public $type;
    public $status;
    public $subTotal;
    public $taxTotal;
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

