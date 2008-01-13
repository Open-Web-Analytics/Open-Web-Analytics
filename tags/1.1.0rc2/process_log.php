#!/usr/local/bin/php -q

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

require_once 'owa_env.php';
require_once 'asyncEventProcessor.php';

/**
 * Batch Event Processing Script
 * 
 * This script should be run by another script or scheduled by a CRON type
 * scheduler.
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

// parse args into it's own array
if ($argv):
   for ($i=1; $i<count($argv);$i++)
   {
       $it = split("=",$argv[$i]);
       $_argv[$it[0]] = $it[1];
   }

endif;

// create instance of OWA
$config = array();
$config['async_db'] = false;
$owa = new asyncEventProcessor($config);

//normal run
if(empty($_argv)):
	
	$owa->process_standard();
	return;   
// Process a specific file
// syntax is: file=filename.txt
elseif (!empty($_argv['file'])):
	
	$owa->process_specific($config['async_log_dir'].$_argv['file']);
	return;

endif;



?>