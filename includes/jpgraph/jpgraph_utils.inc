<?php
/*=======================================================================
// File: 	JPGRAPH_UTILS.INC
// Description: Collection of non-essential "nice to have" utilities 
// Created: 	2005-11-20
// Author:	Johan Persson (johanp@aditus.nu)
// Ver:		$Id$
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
	    JpGraphError::Raise('FuncGenerator : No function specified. ');
			
	@eval($t);
		
	// If there is an error in the function specifcation this is the only
	// way we can discover that.
	if( empty($xa) || empty($ya) )
	    JpGraphError::Raise('FuncGenerator : Syntax error in function specification ');
				
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
// CLASS MGraph
// Description: Create a container image that can hold several graph 
//=============================================================================
class MGraph {
    var $img=NULL;
    var $iCnt=0,$iGraphs = array(); // image_handle, x, y, fx, fy, sizex, sizey
    var $iFillColor='white', $iCurrentColor=0;
    var $lm=0,$rm=0,$tm=0,$bm=0;
    var $iDoFrame = FALSE, $iFrameColor = 'black', $iFrameWeight = 1;
    var $iLineWeight = 1;
    var $expired=false;
    var $img_format='png',$image_quality=75;
    var $iWidth=NULL,$iHeight=NULL;
    var $background_image='',$background_image_center=true,
	$backround_image_format='',$background_image_mix=100,
	$background_image_y=NULL, $background_image_x=NULL;


    // Create a new instane of the combined graph
    function MGraph($aWidth=NULL,$aHeight=NULL) {
	$this->iWidth = $aWidth;
	$this->iHeight = $aHeight;
    }

    // Specify background fill color for the combined graph
    function SetFillColor($aColor) {
	$this->iFillColor = $aColor;
    }

    // Add a frame around the combined graph
    function SetFrame($aFlg,$aColor='black',$aWeight=1) {
	$this->iDoFrame = $aFlg;
	$this->iFrameColor = $aColor;
	$this->iFrameWeight = $aWeight;
    }

    // Specify a background image blend    
    function SetBackgroundImageMix($aMix) {
	$this->background_image_mix = $aMix ;
    }

    // Specify a background image
    function SetBackgroundImage($aFileName,$aCenter_aX=NULL,$aY=NULL) {
	// Second argument can be either a boolean value or 
	// a numeric
	$aCenter=TRUE;
	$aX=NULL;

	if( $GLOBALS['gd2'] && !USE_TRUECOLOR ) {
	    JpGraphError::Raise("You are using GD 2.x and are trying to use a background images on a non truecolor image. To use background images with GD 2.x you <b>must</b> enable truecolor by setting the USE_TRUECOLOR constant to TRUE. Due to a bug in GD 2.0.1 using any truetype fonts with truecolor images will result in very poor quality fonts.");
	}
	if( is_numeric($aCenter_aX) ) {
	    $aX=$aCenter_aX;
	}

	// Get extension to determine image type
	$e = explode('.',$aFileName);
	if( !$e ) {
	    JpGraphError::Raise('Incorrect file name for MGraph::SetBackgroundImage() : '.$aFileName.' Must have a valid image extension (jpg,gif,png) when using autodetection of image type');
	}
	
	$valid_formats = array('png', 'jpg', 'gif');
	$aImgFormat = strtolower($e[count($e)-1]);
	if ($aImgFormat == 'jpeg')  {
	    $aImgFormat = 'jpg';
	}
	elseif (!in_array($aImgFormat, $valid_formats) )  {
	    JpGraphError::Raise('Unknown file extension ($aImgFormat) in MGraph::SetBackgroundImage() for filename: '.$aFileName);
	}    

	$this->background_image = $aFileName;
	$this->background_image_center=$aCenter;
	$this->background_image_format=$aImgFormat;
	$this->background_image_x = $aX;
	$this->background_image_y = $aY;
    }


    // Private helper function for backgound image
    function _loadBkgImage($aFile='') {
	if( $aFile == '' )
	    $aFile = $this->background_image;

	// Remove case sensitivity and setup appropriate function to create image
	// Get file extension. This should be the LAST '.' separated part of the filename
	$e = explode('.',$aFile);
	$ext = strtolower($e[count($e)-1]);
	if ($ext == "jpeg")  {
	    $ext = "jpg";
	}
	
	if( trim($ext) == '' ) 
	    $ext = 'png';  // Assume PNG if no extension specified

	$supported = imagetypes();
	if( ( $ext == 'jpg' && !($supported & IMG_JPG) ) ||
	    ( $ext == 'gif' && !($supported & IMG_GIF) ) ||
	    ( $ext == 'png' && !($supported & IMG_PNG) ) ) {
	    JpGraphError::Raise('The image format of your background image ('.$aFile.') is not supported in your system configuration. ');
	}

	if( $ext == "jpg" || $ext == "jpeg") {
	    $f = "imagecreatefromjpeg";
	    $ext = "jpg";
	}
	else {
	    $f = "imagecreatefrom".$ext;
	}

	$img = @$f($aFile);
	if( !$img ) {
	    JpGraphError::Raise(" Can't read background image: '".$aFile."'");   
	}
	return $img;
    }	

    function _strokeBackgroundImage() {
	if( $this->background_image == '' ) 
	    return;

	$bkgimg = $this->_loadBkgImage();
	// Background width & Heoght
	$bw = imagesx($bkgimg);
	$bh = imagesy($bkgimg);
	// Canvas width and height
	$cw = imagesx($this->img);
	$ch = imagesy($this->img);

	if( $this->background_image_x === NULL || $this->background_image_y === NULL ) {
	    if( $this->background_image_center ) {
		// Center original image in the plot area
		$x = round($cw/2-$bw/2); $y = round($ch/2-$bh/2);
	    }
	    else {
		// Just copy the image from left corner, no resizing
		$x=0; $y=0;
	    }			
	}
	else {
	    $x = $this->background_image_x;
	    $y = $this->background_image_y;
	}
	$this->_imageCp($bkgimg,$x,$y,0,0,$bw,$bh,$this->background_image_mix);
    }

    function _imageCp($aSrcImg,$x,$y,$fx,$fy,$w,$h,$mix=100) {
	imagecopymerge($this->img,$aSrcImg,$x,$y,$fx,$fy,$w,$h,$mix);
    }

    function _imageCreate($aWidth,$aHeight) {
	if( $aWidth <= 1 || $aHeight <= 1 ) {
	    JpGraphError::Raise("Illegal sizes specified for width or height when creating an image, (width=$aWidth, height=$aHeight)");
	}
	if( @$GLOBALS['gd2']==true && USE_TRUECOLOR ) {
	    $this->img = @imagecreatetruecolor($aWidth, $aHeight);
	    if( $this->img < 1 ) {
		die("<b>JpGraph Error:</b> Can't create truecolor image. Check that you really have GD2 library installed.");
	    }
	    ImageAlphaBlending($this->img,true);
	} else {
	    $this->img = @imagecreate($aWidth, $aHeight);	
	    if( $this->img < 1 ) {
		die("<b>JpGraph Error:</b> Can't create image. Check that you really have the GD library installed.");
	    }
	}
    }

    function _polygon($p,$closed=FALSE) {
	if( $this->iLineWeight==0 ) return;
	$n=count($p);
	$oldx = $p[0];
	$oldy = $p[1];
	for( $i=2; $i < $n; $i+=2 ) {
	    imageline($this->img,$oldx,$oldy,$p[$i],$p[$i+1],$this->iCurrentColor);
	    $oldx = $p[$i];
	    $oldy = $p[$i+1];
	}
	if( $closed ) {
	    imageline($this->img,$p[$n*2-2],$p[$n*2-1],$p[0],$p[1],$this->iCurrentColor);
	}
    }

    function _filledPolygon($pts) {
	$n=count($pts);
	for($i=0; $i < $n; ++$i) 
	    $pts[$i] = round($pts[$i]);
	imagefilledpolygon($this->img,$pts,count($pts)/2,$this->iCurrentColor);
    }
	
    function _rectangle($xl,$yu,$xr,$yl) {
	for($i=0; $i < $this->iLineWeight; ++$i ) 
	    $this->_polygon(array($xl+$i,$yu+$i,$xr-$i,$yu+$i,
				  $xr-$i,$yl-$i,$xl+$i,$yl-$i,
				  $xl+$i,$yu+$i));
    }
	
    function _filledRectangle($xl,$yu,$xr,$yl) {
	$this->_filledPolygon(array($xl,$yu,$xr,$yu,$xr,$yl,$xl,$yl));
    }

    function _setColor($aColor) {
	$this->iCurrentColor = $this->iRGB->Allocate($aColor);
    }

    function AddMix($aGraph,$x=0,$y=0,$mix=100,$fx=0,$fy=0,$w=0,$h=0) {
	$this->_gdImgHandle($aGraph->Stroke( _IMG_HANDLER),$x,$y,$fx=0,$fy=0,$w,$h,$mix);
   }

   function Add($aGraph,$x=0,$y=0,$fx=0,$fy=0,$w=0,$h=0) {
	$this->_gdImgHandle($aGraph->Stroke( _IMG_HANDLER),$x,$y,$fx=0,$fy=0,$w,$h);
   }

    function _gdImgHandle($agdCanvas,$x,$y,$fx=0,$fy=0,$w=0,$h=0,$mix=100) {
	if( $w == 0 )  $w = @imagesx($agdCanvas);
	if( $w === NULL ) {
	    JpGraphError::Raise('Argument to MGraph::Add() is not a valid GD image handle.');
	    return;
	}
	if( $h == 0 )  $h = @imagesy($agdCanvas);
	$this->iGraphs[$this->iCnt++] = array($agdCanvas,$x,$y,$fx,$fy,$w,$h,$mix);
    }

    function SetMargin($lm,$rm,$tm,$bm) {
	$this->lm = $lm;
	$this->rm = $rm;
	$this->tm = $tm;
	$this->bm = $bm;
    }

    function SetExpired($aFlg=true) {
	$this->expired = $aFlg;
    }

    // Generate image header
    function Headers() {
	
	// In case we are running from the command line with the client version of
	// PHP we can't send any headers.
	$sapi = php_sapi_name();
	if( $sapi == 'cli' )
	    return;
	
	if( headers_sent() ) {
	    
	    echo "<table border=1><tr><td><font color=darkred size=4><b>JpGraph Error:</b> 
HTTP headers have already been sent.</font></td></tr><tr><td><b>Explanation:</b><br>HTTP headers have already been sent back to the browser indicating the data as text before the library got a chance to send it's image HTTP header to this browser. This makes it impossible for the library to send back image data to the browser (since that would be interpretated as text by the browser and show up as junk text).<p>Most likely you have some text in your script before the call to <i>Graph::Stroke()</i>. If this texts gets sent back to the browser the browser will assume that all data is plain text. Look for any text, even spaces and newlines, that might have been sent back to the browser. <p>For example it is a common mistake to leave a blank line before the opening \"<b>&lt;?php</b>\".</td></tr></table>";

	    die();

	}	
	
	if ($this->expired) {
	    header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
	    header("Last-Modified: " . gmdate("D, d M Y H:i:s") . "GMT");
	    header("Cache-Control: no-cache, must-revalidate");
	    header("Pragma: no-cache");
	}
	header("Content-type: image/$this->img_format");
    }

    function SetImgFormat($aFormat,$aQuality=75) {
	$this->image_quality = $aQuality;
	$aFormat = strtolower($aFormat);
	$tst = true;
	$supported = imagetypes();
	if( $aFormat=="auto" ) {
	    if( $supported & IMG_PNG )
		$this->img_format="png";
	    elseif( $supported & IMG_JPG )
		$this->img_format="jpeg";
	    elseif( $supported & IMG_GIF )
		$this->img_format="gif";
	    else
		JpGraphError::Raise(" Your PHP (and GD-lib) installation does not appear to support any known graphic formats.".
				    "You need to first make sure GD is compiled as a module to PHP. If you also want to use JPEG images".
				    "you must get the JPEG library. Please see the PHP docs for details.");
				
	    return true;
	}
	else {
	    if( $aFormat=="jpeg" || $aFormat=="png" || $aFormat=="gif" ) {
		if( $aFormat=="jpeg" && !($supported & IMG_JPG) )
		    $tst=false;
		elseif( $aFormat=="png" && !($supported & IMG_PNG) ) 
		    $tst=false;
		elseif( $aFormat=="gif" && !($supported & IMG_GIF) ) 	
		    $tst=false;
		else {
		    $this->img_format=$aFormat;
		    return true;
		}
	    }
	    else 
		$tst=false;
	    if( !$tst )
		JpGraphError::Raise(" Your PHP installation does not support the chosen graphic format: $aFormat");
	}
    }

    // Stream image to browser or to file
    function Stream($aFile="") {
	$func="image".$this->img_format;
	if( $this->img_format=="jpeg" && $this->image_quality != null ) {
	    $res = @$func($this->img,$aFile,$this->image_quality);
	}
	else {
	    if( $aFile != "" ) {
		$res = @$func($this->img,$aFile);
	    }
	    else
		$res = @$func($this->img);
	}
	if( !$res )
	    JpGraphError::Raise("Can't create or stream image to file $aFile Check that PHP has enough permission to write a file to the current directory.");
    }

    function Stroke($aFileName='') {
	// Find out the necessary size for the container image
	$w=0; $h=0;
	for($i=0; $i < $this->iCnt; ++$i ) {
	    $maxw = $this->iGraphs[$i][1]+$this->iGraphs[$i][5];
	    $maxh = $this->iGraphs[$i][2]+$this->iGraphs[$i][6];
	    $w = max( $w, $maxw );
	    $h = max( $h, $maxh );
	}
	$w += $this->lm+$this->rm;
	$h += $this->tm+$this->bm;

	// User specified width,height overrides
	if( $this->iWidth !== NULL ) $w = $this->iWidth;
	if( $this->iHeight!== NULL ) $h = $this->iHeight;

	$this->_imageCreate($w,$h);
	$this->iRGB = new RGB($this->img);

	$this->_setcolor($this->iFillColor);
	$this->_filledRectangle(0,0,$w-1,$h-1);

	$this->_strokeBackgroundImage();

	if( $this->iDoFrame ) {
	    $this->_setColor($this->iFrameColor);
	    $this->iLineWeight=$this->iFrameWeight;
	    $this->_rectangle(0,0,$w-1,$h-1);
	}

	// Copy all sub graphs to the container
	for($i=0; $i < $this->iCnt; ++$i ) {
	    $this->_imageCp($this->iGraphs[$i][0],
			    $this->iGraphs[$i][1]+$this->lm,$this->iGraphs[$i][2]+$this->tm,
			    $this->iGraphs[$i][3],$this->iGraphs[$i][4],
			    $this->iGraphs[$i][5],$this->iGraphs[$i][6],
			    $this->iGraphs[$i][7]);
	}

	// Output image
	if( $aFileName == _IMG_HANDLER ) {
	    return $this->img;
	}
	else {
	    $this->Headers();
	    $this->Stream($aFileName);
	}
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

	// Uncomment this line to put a label at the very right data pos
	// $tickPositions[$i] = $datax[$n-1];

	return array($tickPositions,$minTickPositions);
    }

}


?>