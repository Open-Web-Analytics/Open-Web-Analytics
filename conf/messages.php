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
    2004 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Log in to view this page.' )],
    2005 => ['headline' => \OWA\Core\CoreAPI::t( 'This form is no longer valid' ), 'message' => \OWA\Core\CoreAPI::t( 'The form expired or was opened under another account. Start again from the previous screen.' )],
    2010 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Logged out.' )],
    2011 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'The password reset link is not valid.' )],

    // Options/Configuration related
    2500 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Options saved.' )],
    2501 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Module activated.' )],
    2502 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Module deactivated.' )],
    2503 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Options reset to their defaults.' )],
    // 2504 was declared TWICE -- 'Entity %s Schema Created.' and then 'Goal
    // Saved.' -- and in a PHP array literal the later key silently wins. The
    // schema message moved to 2505; the goal one keeps 2504 because
    // OptionsGoalEdit sets it.
    2504 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Goal saved.' )],
    2505 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Schema created for entity %s.' )],

    // Custom reports. Their own codes rather than borrowing 2504, which is why
    // saving a custom report used to report "Goal Saved."
    2510 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Custom report created.' )],
    2511 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Custom report saved.' )],
    2512 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Custom report deleted.' )],
    2513 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'The custom report could not be deleted.' )],

    // User management
    3000 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'User added.' )],
    3001 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'That user name is already taken.' )],
    3002 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'The form has errors.' )],
    3003 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'User profile saved.' )],
    3004 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'User account deleted.' )],
    3005 => ['message' => \OWA\Core\CoreAPI::t( 'Enter your new password.' )],
    3006 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Log in with your new password.' )],
    3007 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'The passwords do not match.' )],
    3008 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'The password must be at least %s characters.' )],
    3009 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'A user with that email address already exists.' )],
    3010 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'No user has that email address.' )],
    3011 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'The user profile could not be updated.' )],
    3012 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Could not connect to the database.' )],

    // Sites management
    3200 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Fill in all required fields.' )],
    3201 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Profile updated.' )],
    3202 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Site added.' )],
    3203 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'The site could not be added.' )],
    // Archived, not deleted: nothing is destroyed, so the wording does not
    // say it was. 3205 is the Property's.
    3204 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Observation Profile deleted. An administrator can restore its data.' )],
    3205 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Property and its Observation Profiles deleted. An administrator can restore their data.' )],
    3206 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'A site with that domain already exists.' )],
    3207 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Domain is required.' )],
    // 3209 was shadowed by a duplicate 3208, as 2504 was, and moved to its own code.
    3208 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Remove http:// from the start of the domain.' )],
    3209 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'That site does not exist.' )],

    // Custom dimensions. Registering writes a row; the ALTER happens later, under
    // the cube build's lock, so the column is not there yet.
    3210 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Custom dimension registered. Its cube column is added within a few minutes and filled from then on; rebuild the cube to fill past data.' )],
    3211 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Custom dimension removed. Its cube column is dropped at the next build. The events are kept: registering it again and rebuilding restores it.' )],

    // Install
    3300 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Could not connect to the database. Check the connection settings in the configuration file.' )],
    3301 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'OWA requires PHP 8.2 or later.' )],
    3302 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'The database schema could not be installed. See the error log.' )],
    3303 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Default site added.' )],
    3304 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Admin user added.' )],
    3305 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Base database schema installed.' )],
    3306 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'That user ID already exists.' )],
    3307 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'The updates failed. See the error log.' )],
    3308 => ['headline' => \OWA\Core\CoreAPI::t( 'Success' ), 'message' => \OWA\Core\CoreAPI::t( 'Updates applied.' )],
    3309 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Site domain is required.' )],
    // 'E-mail Address is required.' was shadowed here and moved to 3312, NOT
    // 3311 -- three controllers set 3311 for the CLI-update message.
    3310 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Password is required.' )],
    3312 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Email address is required.' )],
    3311 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Apply these updates from the command line: <code>php cli.php cmd=update</code>' )],

    // Graph related
    3500 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'There is no data for this period.' )],

    // Report related
    3600 => ['headline' => \OWA\Core\CoreAPI::t( 'Error' ), 'message' => \OWA\Core\CoreAPI::t( 'Unknown error.' )],
];


?>