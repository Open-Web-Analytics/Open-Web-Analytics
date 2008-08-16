<?php
/*
 * Sparkline PHP Graphing Library
 * Copyright 2004 James Byers <jbyers@users.sf.net>
 * http://sparkline.org
 *
 * Sparkline is distributed under a BSD License.  See LICENSE for details.
 *
 * $Id: baseball.php,v 1.8 2004/11/13 18:50:17 jbyers Exp $
 *
 * baseball shows a simple whisker graph of two very different postseasons
 *
 * parameters:  t  team [yankees|redsox]
 *              
 */

// win/loss, home, shutout
// 2004 postseason, source MLB.com
// if anyone has full season data in a workable format, please let me know
//  - jbyers@users.sf.net
//
$data['yankees'] = array(0  => array(0, 1, 1),
                         1  => array(1, 1, 0),
                         2  => array(1, 0, 0),
                         3  => array(1, 0, 0),
                         4  => array(1, 1, 0),
                         5  => array(1, 1, 0),
                         6  => array(1, 0, 0),
                         7  => array(0, 0, 0),
                         8  => array(0, 0, 0),
                         9  => array(0, 1, 0),
                         10 => array(0, 1, 0));

$data['redsox'] = array(0  => array(1, 0, 0),
                        1  => array(1, 0, 0),
                        2  => array(1, 1, 0),
                        3  => array(0, 0, 0),
                        4  => array(0, 0, 0),
                        5  => array(0, 1, 0),
                        6  => array(1, 1, 0),
                        7  => array(1, 1, 0),
                        8  => array(1, 0, 0),
                        9  => array(1, 0, 0),
                        10 => array(1, 1, 0),
                        11 => array(1, 1, 0),
                        12 => array(1, 0, 0),
                        13 => array(1, 0, 1));

if (!isset($_GET['t']) ||
    ($_GET['t'] != 'yankees' &&
     $_GET['t'] != 'redsox')) {
  die('bad team name; need ?t=yankees or ?t=redsox');
}

//////////////////////////////////////////////////////////////////////////////
// build sparkline using standard flow:
//   construct, set, render, output
//
require_once('../lib/Sparkline_Bar.php');

$sparkline = new Sparkline_Bar();
$sparkline->SetDebugLevel(DEBUG_NONE);
//$sparkline->SetDebugLevel(DEBUG_ERROR | DEBUG_WARNING | DEBUG_STATS | DEBUG_CALLS, '../log.txt');

$sparkline->SetBarWidth(1);
$sparkline->SetBarSpacing(2);

$i = 0;
while (list(, $v) = each($data[$_GET['t']])) {
  // set bar color red if shutout
  //
  $color = 'black';
  if ($v[2]) {
    $color = 'red';
  }  

  // set bar underscore boolean if home game
  //
  $underscore = false;
  if ($v[1]) {
    $underscore = true;
  }

  // convert (W, L) to (1, -1)
  //
  $sparkline->SetData($i, ($v[0] * 2) - 1, $color, $underscore);
  $i++;
}
$sparkline->Render(16); // height only for Sparkline_Bar

$sparkline->Output();

?>
