<?php
/**
 * PEAR, the PHP Extension and Application Repository
 *
 * Command line interface
 *
 * PHP versions 4 and 5
 *
 * @category   pear
 * @package    PEAR
 * @author     Stig Bakken <ssb@php.net>
 * @author     Tomas V.V.Cox <cox@idecnet.com>
 * @copyright  1997-2009 The Authors
 * @license    http://opensource.org/licenses/bsd-license.php New BSD License
 * @version    CVS: $Id: peclcmd.php 276392 2009-02-25 00:06:23Z dufuz $
 * @link       http://pear.php.net/package/PEAR
 */

/**
 * @nodep Gtk
 */
if ('@include_path@' != '@'.'include_path'.'@') {
    ini_set('include_path', '@include_path@');
    $raw = false;
} else {
    // this is a raw, uninstalled pear, either a cvs checkout, or php distro
    $raw = true;
}
define('PEAR_RUNTYPE', 'pecl');
require_once 'pearcmd.php';
/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * indent-tabs-mode: nil
 * mode: php
 * End:
 */
// vim600:syn=php

?>
