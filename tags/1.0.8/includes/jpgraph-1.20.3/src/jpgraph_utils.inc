<?php
/*=======================================================================
// File: 	JPGRAPH_UTILS.INC
// Description: Collection of non-essential "nice to have" utilities 
// Created: 	2005-11-20
// Author:	Johan Persson (johanp@aditus.nu)
// Ver:		$Id: jpgraph_utils.inc 504 2006-02-04 15:51:47Z ljp $
//
// Copyright (c) Aditus Consulting. All rights reserved.
//========================================================================
*/

//===================================================
// CLASS FuncGenerator
// Description: Utility class to help generate data for function plots. 
// The class supports both parametric and regular functions.
//===================================================
class FuncGenerator {
    var $iFunc='',$iXFunc='',$iMin,$iMax,$iStepSize;
	
    function FuncGenerator($aFunc,$aXFunc='') {
	$this->iFunc = $aFunc;
	$this->iXFunc = $aXFunc;
    }
	
    function E($aXMin,$aXMax,$aSteps=50) {
	$this->iMin = $aXMin;
	$this->iMax = $aXMax;
	$this->iStepSize = ($aXMax-$aXMin)/$aSteps;

	if( $this->iXFunc != '' )
	    $t = 'for($i='.$aXMin.'; $i<='.$aXMax.'; $i += '.$this->iStepSize.') {$ya[]='.$this->iFunc.';$xa[]='.$this->iXFunc.';}';
	elseif( $this->iFunc != '' )
	    $t = 'for($x='.$aXMin.'; $x<='.$aXMax.'; $x += '.$this->iStepSize.') {$ya[]='.$this->iFunc.';$xa[]=$x;} $x='.$aXMax.';$ya[]='.$this->iFunc.';$xa[]=$x;';
	else
	    JpGraphError::RaiseL(24001);//('FuncGenerator : No function specified. ');
			
	@eval($t);
		
	// If there is an error in the function specifcation this is the only
	// way we can discover that.
	if( empty($xa) || empty($ya) )
	    JpGraphError::RaiseL(24002);//('FuncGenerator : Syntax error in function specification ');
				
	return array($xa,$ya);
    }
}

//=============================================================================
// CLASS SymChar
// Description: Code values for some commonly used characters that 
//              normally isn't available directly on the keyboard, for example
//              mathematical and greek symbols.
//=============================================================================
class  SymChar {
    function Get($aSymb,$aCapital=FALSE) {
        static $iSymbols = array(
    /* Greek */
	array('alpha','03B1','0391'),
	array('beta','03B2','0392'),
	array('gamma','03B3','0393'),
	array('delta','03B4','0394'),
	array('epsilon','03B5','0395'),
	array('zeta','03B6','0396'),
	array('ny','03B7','0397'),
	array('eta','03B8','0398'),
	array('theta','03B8','0398'),
	array('iota','03B9','0399'),
	array('kappa','03BA','039A'),
	array('lambda','03BB','039B'),
	array('mu','03BC','039C'),
	array('nu','03BD','039D'),
	array('xi','03BE','039E'),
	array('omicron','03BF','039F'),
	array('pi','03C0','03A0'),
	array('rho','03C1','03A1'),
	array('sigma','03C3','03A3'),
	array('tau','03C4','03A4'),
	array('upsilon','03C5','03A5'),
	array('phi','03C6','03A6'),
	array('chi','03C7','03A7'),
	array('psi','03C8','03A8'),
	array('omega','03C9','03A9'),
    /* Money */
	array('euro','20AC'),
	array('yen','00A5'),
	array('pound','20A4'),
    /* Math */
	array('approx','2248'),
	array('neq','2260'),
	array('not','2310'),
	array('def','2261'),
	array('inf','221E'),
	array('sqrt','221A'),
	array('int','222B'),
    /* Misc */
	array('copy','00A9'),
	array('para','00A7'));

	$n = count($iSymbols);
	$i=0;
	$found = false;
	$aSymb = strtolower($aSymb);
	while( $i < $n && !$found ) {
	    $found = $aSymb === $iSymbols[$i++][0];
	}
	if( $found ) {
	    $ca = $iSymbols[--$i];
	    if( $aCapital && count($ca)==3 ) 
		$s = $ca[2];
	    else
		$s = $ca[1];
	    return sprintf('&#%04d;',hexdec($s));
	}
	else
	    return '';
    }
}


//=============================================================================
// CLASS DateScaleUtils
// Description: Help to create a manual date scale
//=============================================================================
DEFINE('DSUTILS_MONTH1',1); // Major and minor ticks on a monthly basis

class DateScaleUtils {

    function GetTicks($aData,$aType=1) {

	//
	// Find out the range of the data in order to get the limits for the loops
	// that creates the position for the labels. This code is generic and can be 
	// used for any ranges of the data.
	//
	$n = count($aData);
	$startmonth = date('n',$aData[0]);
	$startday = date('j',$aData[0]);
	$startyear = date('Y',$aData[0]);
	$endmonth = date('n',$aData[$n-1]);
	$endyear = date('Y',$aData[$n-1]);
	$endday = date('j',$aData[$n-1]);

	//
	// Now create the positions for all the ticks. In this example we
	// put a tick at the start of every month and also on the very 
	// first and last X-position.
	// 
	$tickPositions = array();
	$minTickPositions = array();
	$i=0;$j=0;

	// Uncomment this line to put a label at the very left data pos
	// $tickPositions[$i++] = $datax[0];

	$m = $startmonth;
	$y = $startyear;
	// Skip the first month label if it is before the startdate
	if( $startday == 1 ) {
	    $tickPositions[$i++] = mktime(0,0,0,$m,1,$y);
	}
	if( $startday < 15 ) {
	    $minTickPositions[$j++] = mktime(0,0,0,$m,15,$y);
	}
	++$m;

	// Loop through all the years included in the scale
	for($y=$startyear; $y <= $endyear; ++$y ) {
	    // Loop through all the months. There are three cases to consider:
	    // 1. We are in the first year and must start with the startmonth
	    // 2. We are in the end year and we must stop at last month of the scale
	    // 3. A year in between where we run through all the 12 months
	    $stopmonth = $y == $endyear ? $endmonth : 12;
	    while( $m <= $stopmonth ) {
		switch( $aType ) {
		    case 1: 
			// Set minor tick at the middle of the month
			if( $m <= $stopmonth ) {
			    if( !($y==$endyear && $m==$stopmonth && $endday < 15) ) 
				$minTickPositions[$j++] = mktime(0,0,0,$m,15,$y);
			}
			// Major at month 
			// Get timestamp of first hour of first day in each month
			$tickPositions[$i++] = mktime(0,0,0,$m,1,$y);

			break;
		}
		++$m;
	    }
	    $m=1;
	}

	// For the case where all dates are within the same month
	// we want to make sure we have at least two ticks on the scale
	// since the scale want work properly otherwise
	if($startmonth == $endmonth && $startyear == $endyear && $aType==1 ) {
	    $tickPositions[$i++] = mktime(0 ,0 ,0, $startmonth + 1, 1, $startyear);
	} 

	// Uncomment this line to put a label at the very right data pos
	// $tickPositions[$i] = $datax[$n-1];

	return array($tickPositions,$minTickPositions);
    }

}

//=============================================================================
// Class ReadFileData
//=============================================================================
Class ReadFileData {

    //----------------------------------------------------------------------------
    // Desciption:
    // Read numeric data from a file. 
    // Each value should be separated by either a new line or by a specified 
    // separator character (default is ',').
    // Before returning the data each value is converted to a proper float 
    // value. The routine is robust in the sense that non numeric data in the 
    // file will be discarded.
    //
    // Returns: 
    // The number of data values read on success, FALSE on failure
    //----------------------------------------------------------------------------
    function FromCSV($aFile,&$aData,$aSepChar=',',$aMaxLineLength=1024) {
	$rh = fopen($aFile,'r');
	if( $rh === false )
	    return false;
	$tmp = array();
	$lineofdata = fgetcsv($rh, 1000, ',');
	while ( $lineofdata !== FALSE) {
	    $tmp = array_merge($tmp,$lineofdata);
	    $lineofdata = fgetcsv($rh, $aMaxLineLength, $aSepChar);
	}
	fclose($rh);

	// Now make sure that all data is numeric. By default
	// all data is read as strings
	$n = count($tmp);
	$aData = array();
	$cnt=0;
	for($i=0; $i < $n; ++$i) {
	    if( $tmp[$i] !== "" ) {
		$aData[$cnt++] = floatval($tmp[$i]);
	    }
	}
	return $cnt;
    }
}

?>