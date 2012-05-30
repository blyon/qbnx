<?php
/**
 * Quickbooks & Nexternal Integration Script.
 *
 * Collect Customer and Invoice Data from Nexternal or Quickbooks and pass
 * it to the other system.
 *
 * PHP version 5.3
 *
 * @author   Brandon Lyon <brandon@lyonaround.com>
 * @version  GIT:<git_id>
 * @arguments
 * Arguments:
 *   -h     Help Menu
 *   -q     Push data to Quickbooks from Nexternal
 *   -n     Push data to Nexternal from Quickbooks
 *   -t     Define duration of Time to sync:
 *           day, week, month, or time in seconds.
 *
 * Example: ./qbnx.php -n -t week
 */


// Include the Controllers.
require_once dirname(__FILE__) . '/constants.php';
require_once dirname(__FILE__) . '/controllers/Util.php';
require_once dirname(__FILE__) . '/controllers/Process.php';


// Script Arguments.
$args = array(
    'h' => false,   // Show Help.
    'q' => false,   // Push data to QuickBooks.
    'n' => false,   // Push data to Nexternal.
    't' => 'week',  // Time duration of data to push.
);


// Parse user entered Arguments.
parseArgs($args);

// Check for required args, help, and valid time.
if ($args['h']
    || (!$args['q'] && !$args['n'])
    || !validateTime($args['t'])
) {
    showHelp();
    exit();
}


// Check for Quickbooks Argument.
if ($args['q']) {
    pushNexternalToQuickbooks(
        START_TIME - convertTime($args['t']),
        START_TIME,
        true,
        true
    );
}
