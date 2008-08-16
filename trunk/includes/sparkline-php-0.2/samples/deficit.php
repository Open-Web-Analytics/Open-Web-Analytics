<?php
/*
 * Sparkline PHP Graphing Library
 * Copyright 2004 James Byers <jbyers@users.sf.net>
 * http://sparkline.org
 *
 * Sparkline is distributed under a BSD License.  See LICENSE for details.
 *
 * $Id: deficit.php,v 1.2 2004/11/09 07:05:33 jbyers Exp $
 *
 * deficit shows a bar graph of the US deficit
 *              
 */

// annual deficit data, 1983-2003
// source OMB, http://www.whitehouse.gov/omb/budget/fy2004/hist.html
//
$data = array(1983 => -207.802,
              1984 => -185.367,
              1985 => -212.308,
              1986 => -221.215,
              1987 => -149.728,
              1988 => -155.152,
              1989 => -152.456,
              1990 => -221.195,
              1991 => -269.328,
              1992 => -290.376,
              1993 => -255.087,
              1994 => -203.250,
              1995 => -163.972,
              1996 => -107.473,
              1997 =>  -21.958,
              1998 =>   69.213,
              1999 =>  125.563,
              2000 =>  236.445,
              2001 =>  127.299,
              2002 => -157.802,
              2003 => -304.159);

//////////////////////////////////////////////////////////////////////////////
// build sparkline using standard flow:
//   construct, set, render, output
//
require_once('../lib/Sparkline_Bar.php');

$sparkline = new Sparkline_Bar();
$sparkline->SetDebugLevel(DEBUG_NONE);
//$sparkline->SetDebugLevel(DEBUG_ERROR | DEBUG_WARNING | DEBUG_STATS | DEBUG_CALLS, '../log.txt');

$sparkline->SetBarWidth(2);
$sparkline->SetBarSpacing(1);

while (list($k, $v) = each($data)) {

  // black if positive, red if negative
  //
  $color = 'black';
  if ($v < 0) {
    $color = 'red';
  }

  $sparkline->SetData($k, $v, $color);
}

$sparkline->Render(16); // height only for Sparkline_Bar

$sparkline->Output();

?>
