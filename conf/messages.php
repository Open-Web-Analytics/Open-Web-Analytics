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
    // Deliberately says the same thing whether or not the account exists: the
    // reset form is unauthenticated, so a reply that distinguishes the two
    // confirms which addresses are registered to anyone who asks.
    2000 => ['headline' => \OWA\Core\CoreAPI::t( 'Check your email' ), 'message' => \OWA\Core\CoreAPI::t( 'If an account exists for %s, password reset instructions have been sent to it.' )],
    // Reached only when the address is malformed. It must not mention whether
    // the address is known -- see 2000.
    2001 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Enter a valid email address.' )],
    2002 => ['headline' => \OWA\Core\CoreAPI::t( 'Login failed' ), 'message' => \OWA\Core\CoreAPI::t( 'The user name or password is incorrect.' )],
    2003 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Your account does not have access to this page.' )],
    2005 => ['headline' => \OWA\Core\CoreAPI::t( 'This form is no longer valid' ), 'message' => \OWA\Core\CoreAPI::t( 'The form expired or was opened under another account. Start again from the previous screen.' )],
    2011 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'The password reset link is not valid.' )],
    2012 => ['headline' => \OWA\Core\CoreAPI::t( 'This link has expired' ), 'message' => \OWA\Core\CoreAPI::t( 'A link to set a password works for a limited time. Use "Forgot your password?" to get a new one.' )],

    // Options/Configuration related
    2500 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Options saved.' )],
    2501 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Module activated.' )],
    2502 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Module deactivated.' )],
    2503 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Options reset to their defaults.' )],

    // Custom reports.
    2510 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Custom report created.' )],
    2511 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Custom report saved.' )],
    2512 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Custom report deleted.' )],
    2513 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'The custom report could not be deleted.' )],
    // A funnel refused by the editor or the save: one not on this Property, of
    // one the reader may not report on, or gone; and one of somebody else's.
    2514 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'That funnel does not exist.' )],
    2515 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'That funnel belongs to another user.' )],

    // User management
    3000 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'User added.' )],
    3001 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'That user name is already taken.' )],
    3002 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'The form has errors.' )],
    3003 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'User profile saved.' )],
    3004 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'User account deleted.' )],
    3005 => ['message' => \OWA\Core\CoreAPI::t( 'Enter your new password.' )],
    3006 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Log in with your new password.' )],
    3009 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'A user with that email address already exists.' )],
    3012 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Could not connect to the database.' )],
    3013 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'User added, but the invitation email was not sent. Check the mail settings and the error log, then send the user a password reset from the login screen.' )],
    3014 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'This module stays active while its constant is set in owa-config.php.' )],

    // Sites management
    3200 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Fill in all required fields.' )],
    3201 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Profile updated.' )],
    3202 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Observation Profile added.' )],
    3203 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'The Observation Profile could not be added.' )],
    // Archived, not deleted: nothing is destroyed, so the wording does not
    // say it was. 3205 is the Property's.
    3204 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Observation Profile deleted. An administrator can restore its data.' )],
    3205 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Property and its Observation Profiles deleted. An administrator can restore their data.' )],
    3208 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Remove http:// from the start of the domain.' )],

    // Custom dimensions. Registering writes a row; the ALTER happens later, under
    // the cube build's lock, so the column is not there yet.
    3210 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Custom dimension registered. Its cube column is added within a few minutes and filled from then on; rebuild the cube to fill past data.' )],
    3211 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Custom dimension removed. Its cube column is dropped at the next build. The events are kept: registering it again and rebuilding restores it.' )],
    3212 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Property added. Add an Observation Profile to start collecting its data.' )],
    3213 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Application password revoked.' )],
    3214 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'That application password does not exist.' )],
    3215 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Give the application password a name.' )],
    3216 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'The application password could not be created.' )],
    3217 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Property access updated.' )],
    3218 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'That Property does not exist.' )],
    3219 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Goal event definition saved.' )],
    3220 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Goal event definition deleted.' )],

    // Install
    3302 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'The database schema could not be installed. See the error log.' )],
    3306 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'That user ID already exists.' )],
    3307 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'The updates failed. See the error log.' )],
    3308 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Updates applied.' )],
    3309 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Site domain is required.' )],
    3310 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Password is required.' )],
    3311 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Apply these updates from the command line: <code>php cli.php cmd=update</code>' )],
    3313 => ['headline' => \OWA\Core\CoreAPI::t( 'Updates are already being applied' ), 'message' => \OWA\Core\CoreAPI::t( 'Another request is applying them. Reload this page in a few minutes.' )],

    // Report related
    3600 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Unknown error.' )],
];


?>