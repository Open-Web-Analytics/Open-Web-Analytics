<?php
/*
 Standalone version of get_browser() in PHP
  http://www.php.net/manual/function.get-browser.php
 Detection of the capacities of a Web browser client.
 Requires a compatible browscap.ini database,
  like php_browscap.ini on
  http://www.garykeith.com/browsers/downloads.asp

 Interface:
 - function get_browser_local($user_agent=null,$return_array=false,$db='./browscap.ini')
  [Implied] $user_agent=null: The signature of the browser to be analysed. If this parameter is left to null, then it uses $_SERVER['HTTP_USER_AGENT'].
  [Implied] $return_array=false: When this parameter is activated, the function returns an array instead of an object.
  [Implied] $db='./browscap.ini': Allows specifying the path of the browscap.ini database, otherwise assumes that it is in the current directory.
  [Implied] $cache=false: Specify if the database can be kept in memory, to improve performances when querying this function more than once.
  Returns: An object (or an array, if asked to do so) with the capacities of the browser.

 Typical use:
 {
  if (get_cfg_var('browscap'))
   $browser=get_browser(); //If available, use PHP native function
  else
  {
   require_once('php-local-browscap.php');
   $browser=get_browser_local();
  }
  print_r($browser);
 }

 Version 1.2.1, 2005-11-25, http://alexandre.alapetite.net/doc-alex/php-local-browscap/

 ------------------------------------------------------------------
 Written by Alexandre Alapetite, http://alexandre.alapetite.net/cv/

 Copyright 2005, Licence: Creative Commons "Attribution-ShareAlike 2.0 France" BY-SA (FR),
 http://creativecommons.org/licenses/by-sa/2.0/fr/
 http://alexandre.alapetite.net/divers/apropos/#by-sa
 - Attribution. You must give the original author credit
 - Share Alike. If you alter, transform, or build upon this work,
   you may distribute the resulting work only under a license identical to this one
 - The French law is authoritative
 - Any of these conditions can be waived if you get permission from Alexandre Alapetite
 - Please send to Alexandre Alapetite the modifications you make,
   in order to improve this file for the benefit of everybody

 If you want to distribute this code, please do it as a link to:
 http://alexandre.alapetite.net/doc-alex/php-local-browscap/
*/

$browscapIni=null; //Cache
$browscapPath=''; //Cached database

function get_browser_local($db='', $user_agent=null,$return_array=false,$cache=true)
{//http://alexandre.alapetite.net/doc-alex/php-local-browscap/

 if (($user_agent==null)&&isset($_SERVER['HTTP_USER_AGENT'])) $user_agent=$_SERVER['HTTP_USER_AGENT'];
 
 global $browscapIni;
 global $browscapPath;
 if ((!isset($browscapIni))||(!$cache)||($browscapPath!==$db))
 {
  $browscapIni = parse_ini_file($db,true); //Get php_browscap.ini on http://www.garykeith.com/browsers/downloads.asp
  $browscapPath = $db;
 }
 $cap=null;
 foreach ($browscapIni as $key=>$value)
 {
  if (($key!='*')&&(!array_key_exists('parent',$value))) continue;
  $keyEreg='^'.str_replace(
   array('\\','.','?','*','^','$','[',']','|','(',')','+','{','}','%'),
   array('\\\\','\\.','.','.*','\\^','\\$','\\[','\\]','\\|','\\(','\\)','\\+','\\{','\\}','\\%'),
   $key).'$';
  if (preg_match('%'.$keyEreg.'%i',$user_agent))
  {
   $cap=array('browser_name_regex'=>strtolower($keyEreg),'browser_name_pattern'=>$key)+$value;
   $maxDeep=8;
   while (array_key_exists('parent',$value)&&(--$maxDeep>0))
    $cap+=($value=$browscapIni[$value['parent']]);
   break;
  }
 }
 
 if (!$cache) $browscapIni=null;
 if ($return_array) return $cap;
 else return ((object)$cap);
}
?>
