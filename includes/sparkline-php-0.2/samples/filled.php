<?php
/*
 * Sparkline PHP Graphing Library
 * Copyright 2004 James Byers <jbyers@users.sf.net>
 * http://sparkline.org
 *
 * Sparkline is distributed under a BSD License.  See LICENSE for details.
 *
 * $Id: filled.php,v 1.2 2005/06/02 21:00:32 jbyers Exp $
 *
 * filled shows deficit data in a simulated filled-line mode
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

$sparkline->SetBarWidth(1);
$sparkline->SetBarSpacing(0);
$sparkline->SetBarColorDefault('blue');

$j = 0;
for($i = 1983; $i < sizeof($data) + 1983; $i++) {

  $sparkline->SetData($j++, $data[$i]);

  if (isset($data[$i+1])) {
    $sparkline->SetData($j++, ($data[$i] + $data[$i+1]) / 2);
  }
}

$sparkline->Render(10); // height only for Sparkline_Bar

$sparkline->Output();

?>
