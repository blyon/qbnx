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
DEFINE('ROOT_DIR',                  dirname(__FILE__));
DEFINE('CACHE_DIR',                 ROOT_DIR . "/cache/");
DEFINE('CACHE_EXT',                 '.cache');
DEFINE('NEXTERNAL_ORDER_CACHE',     'NexternalOrders');
DEFINE('NEXTERNAL_CUSTOMER_CACHE',  'NexternalCustomers');
DEFINE('QUICKBOOKS_ORDER_CACHE',    'QuickbooksOrders');
DEFINE('QUICKBOOKS_CUSTOMER_CACHE', 'QuickbooksCustomers');
DEFINE('TOESOX_INTERNAL_CUSTOMERS',  array('80003CBE-1336668106','80003D05-1341509624','80003D0B-1342458686','3240000-1220036156','80003CCD-1336771446'));
DEFINE('MAIL_UPDATES',              'tbudzins@gmail.com, brandon@lyonaround.com');
DEFINE('MAIL_EXCEPTIONS',           'tbudzins@gmail.com, brandon@lyonaround.com');
DEFINE('MAIL_ERRORS',               'tbudzins@gmail.com, brandon@lyonaround.com, denise@toesox.com, sseibert@toesox.com, jpatterson@toesox.com, italybarry@yahoo.com');
DEFINE('MAIL_SUCCESS',              'tbudzins@gmail.com, brandon@lyonaround.com, denise@toesox.com, sseibert@toesox.com, jpatterson@toesox.com, italybarry@yahoo.com');
