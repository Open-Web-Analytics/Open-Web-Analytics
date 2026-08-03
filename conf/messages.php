<?php

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an 'AS IS' BASIS,
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
 * @version        $Revision$
 * @since        owa 1.0.0
 */

$_owa_messages = [
    2000 => ['headline' => 'Success', 'message' => 'An e-mail containing instructions on how to complete the password reset process has been sent to %s'],
    2001 => ['headline' => 'Error', 'message' => 'The e-mail %s was not found in our database. Please check the address and try again.'],
    2002 => ['headline' => 'Login Failed', 'message' => 'Your user name or password did not match.'],
    2003 => ['headline' => 'Error', 'message' => 'Your Account lacks the necessary privileges to access the requested resource.'],
    2004 => ['headline' => 'Error', 'message' => 'You must login to access the requested resource.'],
    2010 => ['headline' => 'Success', 'message' => 'Logout Complete.'],
    2011 => ['headline' => 'Error', 'message' => 'Can\'t find your temporary passkey in the db.'],

    // Options/Configuration related
    2500 => ['headline' => 'Success', 'message' => 'Options Saved.'],
    2501 => ['headline' => 'Success', 'message' => 'The module was activated successfully.'],
    2502 => ['headline' => 'Success', 'message' => 'The module was deactivated successfully.'],
    2503 => ['headline' => 'Success', 'message' => 'Options reset to Default Values.'],
    2504 => ['headline' => 'Success', 'message' => 'Entity %s Schema Created.'],
    2504 => ['headline' => 'Success', 'message' => 'Goal Saved.'],


    //User managment
    3000 => ['headline' => 'Success', 'message' => 'User Added.',],
    3001 => ['headline' => 'Error', 'message' => 'That user name is already taken.'],
    3002 => ['headline' => 'Error', 'message' => 'The form data that you entered contained one or more errors. Please check the data and submit the from again.'],
    3003 => ['headline' => 'Success', 'message' => 'User profile saved.'],
    3004 => ['headline' => 'Success', 'message' => 'User acount deleted.'],
    3005 => ['message' => 'Enter Your New Password',],
    3006 => ['headline' => 'Success', 'message' => 'Please login with your new password.'],
    3007 => ['headline' => 'Error', 'message' => 'Your passwords must match.'],
    3008 => ['headline' => 'Error', 'message' => 'Your password must be %s characters long.',],
    3009 => ['headline' => 'Error', 'message' => 'A user with that email address already exists.',],
    3010 => ['headline' => 'Error', 'message' => 'A user with that email address does not exist.',],
    3011 => ['headline' => 'Error', 'message' => 'Could not update user profile.'],
    3012 => ['headline' => 'Error', 'message' => 'Could not connect the database. Check your settings and try again.'],

    //sites management
    3200 => ['headline' => 'Error', 'message' => 'Please fill in all required fields.'],
    3201 => ['headline' => 'Success', 'message' => 'Site Profile Updated.'],
    3202 => ['headline' => 'Success', 'message' => 'Site Added.'],
    3203 => ['headline' => 'Error', 'message' => 'Site Could not be added'],
    3204 => ['headline' => 'Success', 'message' => 'Site Deleted.'],
    3206 => ['headline' => 'Error', 'message' => 'A site with that domain already exists.'],
    3207 => ['headline' => 'Error', 'message' => 'You must enter a domain when adding a web site.'],
    3208 => ['headline' => 'Error', 'message' => 'That site does not exist.'],
    3208 => ['headline' => 'Error', 'message' => 'Please remove the http:// from your beginning of your domain.'],


    //install
    3300 => ['headline' => 'Error', 'message' => 'Could not connect to the database. Please check the database connection settings in your configuration file and try again.'],
    3301 => ['headline' => 'Error', 'message' => 'This version of OWA requires PHP 5.2.x or higher.'],
    3302 => ['headline' => 'Error', 'message' => 'Database Schema Installation failed. Please check the error log file for more details.'],
    3303 => ['headline' => 'Success', 'message' => 'Default Site Added.'],
    3304 => ['headline' => 'Success', 'message' => 'Admin User Added.'],
    3305 => ['headline' => 'Success', 'message' => 'Base Database Schema Installed.'],
    3306 => ['headline' => 'Error', 'message' => 'User id already exists for some reason.'],
    3307 => ['headline' => 'Error', 'message' => 'Updates failed. Check OWA\'s error log file for more details and try again.'],
    3308 => ['headline' => 'Success', 'message' => 'Updates were applied.'],
    3309 => ['headline' => 'Error', 'message' => 'Site Domain is required.'],
    3310 => ['headline' => 'Error', 'message' => 'E-mail Address is required.'],
    3310 => ['headline' => 'Error', 'message' => 'Password is required.'],
    3311 => ['headline' => 'Error', 'message' => 'These updates must be applied using the command line interface (CLI). Run <code>\'/path/to/php cli.php cmd=update\'</code> from your server\'s command shell to apply these updates. For more information on updating see the install/update page on the wiki.'],

    // Graph related
    3500 => ['headline' => 'Error', 'message' => 'There is no data for\nthis time period.'],

    // Report Related
    3600 => ['headline' => 'Error', 'message' => 'Unknown'],
];


?>