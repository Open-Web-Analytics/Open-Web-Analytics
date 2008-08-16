<?php
/*
 * Sparkline PHP Graphing Library
 * Copyright 2004 James Byers <jbyers@users.sf.net>
 * http://sparkline.org
 *
 * Sparkline is distributed under a BSD License.  See LICENSE for details.
 *
 * $Id: stock_chart3.php,v 1.1 2004/11/16 06:34:35 jbyers Exp $
 *
 * stock_chart3 adds min/max price bounds and an endpoint value label to
 * stock_chart, but without text as in stock_chart2
 *              
 */

//////////////////////////////////////////////////////////////////////////////
// verify parameters; bad form, but die to text error on failure
//
if (!isset($_GET['s']) ||
    !eregi('^[a-z\^]{1,5}$', $_GET['s'])) {
  die('bad ticker ' . $_GET['s']);
}

if (!isset($_GET['y']) ||
    !is_numeric($_GET['y']) ||
    $_GET['y'] > 5 ||
    $_GET['y'] < 0) {
  die('bad year ' . $_GET['y']);
}

//////////////////////////////////////////////////////////////////////////////
// load and process data from Yahoo! ichart csv source
//
$m  = date('n') - 1;
$d  = date('d');
$y2 = date('Y');
$y1 = $y2 - $_GET['y'];

// data sample:
//   0        1     2     3     4     5        6
//   Date,Open,High,Low,Close,Volume,Adj. Close*
//   5-Nov-04,29.21,29.36,29.03,29.31,95337696,29.31
//
$url = "http://ichart.finance.yahoo.com/table.csv?s=" . $_GET['s'] . "&a=$m&b=$d&c=$y1&d=$m&e=$d&f=$y2&g=d&ignore=.csv";
if (!$data = @file($url)) {
  die('error fetching stock data; verify ticker symbol');
}

//////////////////////////////////////////////////////////////////////////////
// build sparkline using standard flow:
//   construct, set, render, output
//
require_once('../lib/Sparkline_Line.php');

$sparkline = new Sparkline_Line();
$sparkline->SetDebugLevel(DEBUG_NONE);
//$sparkline->SetDebugLevel(DEBUG_ERROR | DEBUG_WARNING | DEBUG_STATS | DEBUG_CALLS, '../log.txt');

if (isset($_GET['b'])) {
  $sparkline->SetColorHtml('background', $_GET['b']);
  $sparkline->SetColorBackground('background');
}

$data = array_reverse($data);
$i = 0;
$min  = null;
$max  = null;
$last = null;
while (list(, $v) = each($data)) {
  $elements = explode(',', $v);
  $value    = @trim($elements[6]);

  if (ereg('^[0-9\.]+$', $value)) {

    $sparkline->SetData($i, $value);

    if (null == $max ||
        $value >= $max[1]) {
      $max = array($i, $value);
    }

    if (null == $min ||
        $value <= $min[1]) {
      $min = array($i, $value);
    }

    $last = array($i, $value);

    $i++;
  }
}

// set y-bound, min and max extent lines
//
$sparkline->SetYMin(0);
$sparkline->SetPadding(3);
$sparkline->SetColorHtml('ltgreen', '#00CC00');
$sparkline->SetFeaturePoint($min[0],  $min[1],  'red',     3);
$sparkline->SetFeaturePoint($max[0],  $max[1],  'ltgreen', 3);
$sparkline->SetFeaturePoint($last[0], $last[1], 'blue',    3);

if (isset($_GET['m']) &&
    $_GET['m'] == '0') {
  $sparkline->Render(80, 20);
} else {
  $sparkline->SetLineSize(5); // for renderresampled, linesize is on virtual image
  $sparkline->RenderResampled(80, 20);
}

$sparkline->Output();

?>
