<?php

class Order
{
    public $id;
    public $timestamp;
    public $type;
    public $status;
    public $subTotal;
    public $tax;
    public $shipping;
    public $total;
    public $memo;
    public $location;
    public $ip;
    public $paymentStatus;
    public $paymentMethod;
    public $customer;
    public $billingAddress;
    public $shippingAddress;
    public $products        = array();
    public $discounts       = array();

}

