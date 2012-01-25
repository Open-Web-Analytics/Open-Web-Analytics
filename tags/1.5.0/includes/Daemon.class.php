<?php
/**
 * @package binarychoice.system.unix
 * @since 1.0.3
 */

// Log message levels
define('DLOG_TO_CONSOLE', 1);
define('DLOG_NOTICE', 2);
define('DLOG_WARNING', 4);
define('DLOG_ERROR', 8);
define('DLOG_CRITICAL', 16);

/**
 * Daemon base class
 *
 * Requirements:
 * Unix like operating system
 * PHP 4 >= 4.3.0 or PHP 5
 * PHP compiled with:
 * --enable-sigchild
 * --enable-pcntl
 *
 * @package binarychoice.system.unix
 * @author Michal 'Seth' Golebiowski <seth at binarychoice dot pl>
 * @copyright Copyright 2005 Seth
 * @since 1.0.3
 */
class Daemon
{
   /**#@+
    * @access public
    */
   /**
    * User ID
    * 
    * @var int
    * @since 1.0
    */
   var $userID = 99;

   /**
    * Group ID
    * 
    * @var integer
    * @since 1.0
    */
   var $groupID = 99;
   
   /**
    * Terminate daemon when set identity failure ?
    * 
    * @var bool
    * @since 1.0.3
    */
   var $requireSetIdentity = false;

   /**
    * Path to PID file
    * 
    * @var string
    * @since 1.0.1
    */
   var $pidFileLocation = '/tmp/daemon.pid';

   /**
    * Home path
    * 
    * @var string
    * @since 1.0
    */
   var $homePath = '/';
   /**#@-*/


   /**#@+
    * @access protected
    */
   /**
    * Current process ID
    * 
    * @var int
    * @since 1.0
    */
   var $_pid = 0;

   /**
    * Is this process a children
    * 
    * @var boolean
    * @since 1.0
    */
   var $_isChildren = false;

   /**
    * Is daemon running
    * 
    * @var boolean
    * @since 1.0
    */
   var $_isRunning = false;
   /**#@-*/


   /**
    * Constructor
    *
    * @access public
    * @since 1.0
    * @return void
    */
   function __construct()
   {
      error_reporting(0);
      set_time_limit(0);
      ob_implicit_flush();

   }

   /**
    * Starts daemon
    *
    * @access public
    * @since 1.0
    * @return bool
    */
   function start()
   {
      $this->_logMessage('Starting daemon');

      if (!$this->_daemonize())
      {
         $this->_logMessage('Could not start daemon', DLOG_ERROR);

         return false;
      }


      $this->_logMessage('Running...');

      $this->_isRunning = true;


      while ($this->_isRunning)
      {
         $this->_doTask();
      }

      return true;
   }

   /**
    * Stops daemon
    *
    * @access public
    * @since 1.0
    * @return void
    */
   function stop()
   {
      $this->_logMessage('Stoping daemon');

      $this->_isRunning = false;
   }

   /**
    * Do task
    *
    * @access protected
    * @since 1.0
    * @return void
    */
   function _doTask()
   {
          // override this method
   }

   /**
    * Logs message
    *
    * @access protected
    * @since 1.0
    * @return void
    */
   function _logMessage($msg, $level = DLOG_NOTICE)
   {
         // override this method
   }

   /**
    * Daemonize
    *
    * Several rules or characteristics that most daemons possess:
    * 1) Check is daemon already running
    * 2) Fork child process
    * 3) Sets identity
    * 4) Make current process a session laeder
    * 5) Write process ID to file
    * 6) Change home path
    * 7) umask(0)
    * 
    * @access private
    * @since 1.0
    * @return void
    */
   function _daemonize()
   {
      ob_end_flush();

      if ($this->_isDaemonRunning())
      {
         // Deamon is already running. Exiting
         return false;
      }

      if (!$this->_fork())
      {
         // Coudn't fork. Exiting.
         return false;
      }

      if (!$this->_setIdentity() && $this->requireSetIdentity)
      {
         // Required identity set failed. Exiting
         return false;
      }

      if (!posix_setsid())
      {
         $this->_logMessage('Could not make the current process a session leader', DLOG_ERROR);

         return false;
      }

      if (!$fp = @fopen($this->pidFileLocation, 'w'))
      {
         $this->_logMessage('Could not write to PID file', DLOG_ERROR);

         return false;
      }
      else
      {
         fputs($fp, $this->_pid);
         fclose($fp);
      }

      @chdir($this->homePath);
      umask(0);

      declare(ticks = 1);

      pcntl_signal(SIGCHLD, array(&$this, 'sigHandler'));
      pcntl_signal(SIGTERM, array(&$this, 'sigHandler'));

      return true;
   }

   /**
    * Cheks is daemon already running
    *
    * @access private
    * @since 1.0.3
    * @return bool
    */
   function _isDaemonRunning()
   {
      $oldPid = @file_get_contents($this->pidFileLocation);

      if ($oldPid !== false && posix_kill(trim($oldPid),0))
      {
         $this->_logMessage('Daemon already running with PID: '.$oldPid, (DLOG_TO_CONSOLE | DLOG_ERROR));

         return true;
      }
      else
      {
         return false;
      }
   }

   /**
    * Forks process
    *
    * @access private
    * @since 1.0
    * @return bool
    */
   function _fork()
   {
      $this->_logMessage('Forking...');
      
      if (!function_exists('pcntl_fork')) {
      	$this->_logMessage('Forking 2...');
      }
      $pid = pcntl_fork();
      
      if ($pid == -1) // error
      {
         $this->_logMessage('Could not fork', DLOG_ERROR);

         return false;
      }
      else if ($pid) // parent
      {
         $this->_logMessage('Killing parent');

         exit();
      }
      else // children
      {
         $this->_isChildren = true;
         $this->_pid = posix_getpid();

         return true;
      }
   }

   /**
    * Sets identity of a daemon and returns result
    *
    * @access private
    * @since 1.0
    * @return bool
    */
   function _setIdentity()
   {
      if (!posix_setgid($this->groupID) || !posix_setuid($this->userID))
      {
         $this->_logMessage('Could not set identity', DLOG_WARNING);

         return false;
      }
      else
      {
         return true;
      }
   }

   /**
    * Signals handler
    *
    * @access public
    * @since 1.0
    * @return void
    */
   function sigHandler($sigNo)
   {
      switch ($sigNo)
      {
         case SIGTERM:   // Shutdown
            $this->_logMessage('Shutdown signal');
            exit();
            break;

         case SIGCHLD:   // Halt
            $this->_logMessage('Halt signal');
            while (pcntl_waitpid(-1, $status, WNOHANG) > 0);
            break;
      }
   }

   /**
    * Releases daemon pid file
    * This method is called on exit (destructor like)
    *
    * @access public
    * @since 1.0
    * @return void
    */
   function __destruct()
   {
      if ($this->_isChildren && file_exists($this->pidFileLocation))
      {
         $this->_logMessage('Releasing daemon');

         unlink($this->pidFileLocation);
      }
   }
}
?>