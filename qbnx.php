<?php
/**
 * Quickbooks & Nexternal Integration Script.
 *
 * Collect Customer and Invoice Data from Nexternal or Quickbooks and pass
 * it to the other system.
 *
 * PHP version 5.3
 *
 * @author  Brandon Lyon <brandon@lyonaround.com>
 * @version GIT:<git_id>
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
require_once dirname(__FILE__) . '/includes/Util.php';
require_once dirname(__FILE__) . '/includes/Process.php';


// Script Arguments.
$args = array(
    'h' => false,   // Show Help.
    'q' => false,   // Push data to QuickBooks.
    'n' => false,   // Push data to Nexternal.
    't' => 'week',  // Time duration of data to push.
    'u' => false,   // Update Script.
);


// Parse user entered Arguments.
Util::parseArgs($args);

// Check for required args, help, and valid time.
if ($args['h']
    || (!$args['q'] && !$args['n'] && !$args['u'])
    || !Util::validateTime($args['t'])
) {
    Util::showHelp();
    exit();
}

try {
    // Update Script from Github.
    if ($args['u']) {
        Util::sendMail(MAIL_UPDATES, "ToeSox Code Update", "A code update request has been received!");
        Util::downloadUpdate();
        exit();
    }

    // Check for Quickbooks Argument.
    if ($args['q']) {
        pushNexternalToQuickbooks(
            START_TIME - Util::convertTime($args['t']),
            START_TIME,
            true
        );
    }


    // Check for Nexternal Argument.
    if ($args['n']) {
        pushQuickbooksToNexternal(
            START_TIME - Util::convertTime($args['t']),
            START_TIME,
            true
        );
    }
} catch (Exception $e) {
    $log = Log::getInstance();
    $log->write(Log::ALERT, $e->getMessage());
    Util::sendMail(MAIL_EXCEPTIONS, "ToeSox Exception Handler", implode("\n", array(
        $e->getMessage(),
        sprintf("File: %s", $e->getFile()),
        sprintf("Line: %s", $e->getLine()),
        "",
        "STACK TRACE:",
        $e->getTraceAsString()
    )));
}
