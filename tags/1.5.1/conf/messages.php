<?php

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
// $Id$
//


/**
 * Messages and Strings file
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

$_owa_messages = array(

2000 => array("An e-mail containing instructions on how to complete the password reset process has been sent to %s",1),
2001 => array("The e-mail <B>%s</B> was not found in our database. Please check the address and try again.",1),
2002 => array("<B>Login Failed</B>. Your user name or password did not match.",0),
2003 => array("Your Account lacks the necessary privileges to access the requested resource.",0),
2004 => array("You must login to access the requested resource.",0),
2010 => array("Success. Logout Complete.",0),
2011 => array("Error. Can't find your temporary passkey in the db.",0),

// Options/Configuration related
2500 => array("Options Saved.",0),
2501 => array("The module was activated successfully.",0),
2502 => array("The module was deactivated successfully.",0),
2503 => array("Options reset to Default Values.",0),
2504 => array("Entity %s Schema Created.",1),
2504 => array("Goal Saved.",0),


//User managment
3000 => array("Success. User Added.", 0),
3001 => array("Error. That user name is already taken.",0),
3002 => array("The form data that you entered contained one or more errors. Please check the data and submit the from again."),
3003 => array("Success. User profile saved.",0),
3004 => array("Success. User acount deleted."),
3005 => array("Enter Your New Password", 0),
3006 => array("Success. Please login with your new password.",0),
3007 => array("Error. Your passwords must match.",0),
3008 => array("Error. Your password must be %s characters long.", 1),
3009 => array("Error. A user with that email address already exists.", 0),
3010 => array("A user with that email address does not exist.", 0),
3011 => array("Could not update user profile."),
3012 => array("Could not connect the database. Check your settings and try again.",0),

//sites management
3200 => array("Error. Please fill in all required fields.",0),
3201 => array("Success. Site Profile Updated.",0),
3202 => array("Success. Site Added.",0),
3203 => array("Error. Site Could not be added",0),
3204 => array("Success. Site Deleted.",0),
3206 => array("Error. A site with that domain already exists.",0),
3207 => array("Error. You must enter a domain when adding a web site.",0),
3208 => array("Error. That site does not exist.",0),
3208 => array("Please remove the http:// from your beginning of your domain.",0),


//install
3300 => array("Could not connect to the database. Please check the database connection settings in your configuration file and try again.",0),
3301 => array("This version of OWA requires PHP 5.2.x or higher.",0),
3302 => array("Database Schema Installation failed. Please check the error log file for more details.",0),
3303 => array("Success. Default Site Added.",0),
3304 => array("Success. Admin User Added.",0),
3305 => array("Success. Base Database Schema Installed.",0),
3306 => array("Error. User id already exists for some reason.",0),
3307 => array("Updates failed. Check OWA's error log file for more details and try again.",0),
3308 => array("Success. Updates were applied.",0),
3309 => array("Site Domain is required.",0),
3310 => array("E-mail Address is required.",0),
3310 => array("Password is required.",0),
3311 => array("These updates must be applied using the command line interface (CLI). Run <code>'/path/to/php cli.php cmd=update'</code> from your server's command shell to apply these updates. For more information on updating see the install/update page on the wiki.",0),
// Graph related
3500 => array("There is no data for\nthis time period.",0),

// Report Related
3600 => array("Unknown",0)

);


?>