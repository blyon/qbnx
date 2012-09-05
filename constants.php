<?php
/**
 * Quickbooks & Nexternal Integration Global Constants.
 *
 * PHP version 5.3
 *
 * @author  Brandon Lyon <brandon@lyonaround.com>
 * @version GIT:<git_id>
 */

DEFINE('START_TIME',                time());
DEFINE('MEMORY_CAP',                104857600); // 100 MB
DEFINE('CACHE_DIR',                 dirname(__FILE__) . "/cache/");
DEFINE('CACHE_EXT',                 '.cache');
DEFINE('NEXTERNAL_ORDER_CACHE',     'NexternalOrders');
DEFINE('NEXTERNAL_CUSTOMER_CACHE',  'NexternalCustomers');
DEFINE('QUICKBOOKS_ORDER_CACHE',    'QuickbooksOrders');
DEFINE('QUICKBOOKS_CUSTOMER_CACHE', 'QuickbooksCustomers');
DEFINE('MAIL_EXCEPTIONS',           'tbudzins@gmail.com, brandon@lyonaround.com');
DEFINE('MAIL_ERRORS',               'tbudzins@gmail.com, brandon@lyonaround.com');
DEFINE('MAIL_SUCCESS',              'tbudzins@gmail.com, brandon@lyonaround.com');
