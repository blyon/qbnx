<?php
class Log
{
    const DISPLAY_LEVEL = 3;
    const EMERG = 0;
    const ALERT = 1;
    const CRIT  = 2;
    const ERROR = 3;
    const WARN  = 4;
    const NOTICE= 5;
    const INFO  = 6;
    const DEBUG = 7;
    private static $__instance;
    private $_fh;
    public $messageLength = 80;
    public $directory;


    private function __construct() {}
    private function __clone() {}
    public function __destruct()
    {
        if ($this->_fh) {
            $this->close($this->_fh);
        }
    }


    public static function getInstance()
    {
        if (is_null(self::$__instance)) {
            self::$__instance = new Log;
        }
        return self::$__instance;
    }


    public function open()
    {
        if (empty($this->directory)) {
            throw new Exception("Cannot create log file, no path specified");
        }
        if (!is_dir($this->directory)) {
            throw new Exception("Cannot create log file, invalid directory: " . $this->directory);
        }
        if (!is_writeable($this->directory)) {
            throw new Exception("Cannot create log file, insufficient permission: " . $this->directory);
        }

        if (!preg_match("@/$@", $this->directory)) {
            $this->directory .= "/";
        }

        $file = $this->directory . date('Y-m-d_His') . ".log";
        if (FALSE === ($this->_fh = fopen($file, "a"))) {
            throw new Exception("Failed to create log file: " . $file);
        }
    }


    public function close()
    {
        if ($this->_fh) {
            fclose($this->_fh);
        }
    }


    public function write($level, $data)
    {
        // Open Log File if not already open.
        if (is_null($this->_fh)) {
            $this->open();
        }

        // Validate Level.
        if (!$this->validateLevel($level)) {
            throw new Exception("Invalid Log Level: " . $level);
        }

        // Strip NewLines from data.
        $data = preg_replace("/[\n|\r]/", "", $data);

        // Format and Print Log String.
        $timestamp   = date('Y-m-d H:i:s');
        $levelString = $this->printLevel($level);
        while (!empty($data)) {
            $string = sprintf("%s %s %s\n", $timestamp, $levelString, substr($data, 0, $this->messageLength));
            $data   = (strlen($data) <= $this->messageLength) ? '' : substr($data, $this->messageLength);
            if ($level <= self::DISPLAY_LEVEL) {
                print $string;
            }
            if (!fwrite($this->_fh, $string)) {
                throw new Exception("Failed to write to Log: \n" .$string);
            }
        }
    }


    private function validateLevel($level)
    {
        return in_array($level, array(
            self::EMERG, self::ALERT, self::CRIT, self::ERROR, self::WARN,
            self::NOTICE, self::INFO, self::DEBUG));
    }


    private function printLevel($level)
    {
        $levels = array(
            self::EMERG     => str_pad('EMERG',  6),
            self::ALERT     => str_pad('ALERT',  6),
            self::CRIT      => str_pad('CRIT',   6),
            self::ERROR     => str_pad('ERROR',  6),
            self::WARN      => str_pad('WARN',   6),
            self::NOTICE    => str_pad('NOTICE', 6),
            self::INFO      => str_pad('INFO',   6),
            self::DEBUG     => str_pad('DEBUG',  6),
        );
        return $levels[$level];
    }
}
