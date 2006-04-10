<?php
/*=======================================================================
// File: 	JPGRAPH_CANVAS.PHP
// Description:	Canvas drawing extension for JpGraph
// Created: 	2001-01-08
// Author:	Johan Persson (johanp@aditus.nu)
// Ver:		$Id: jpgraph_canvas.php 21 2005-05-30 20:35:34Z ljp $
//
// Copyright (c) Aditus Consulting. All rights reserved.
//========================================================================
*/

//===================================================
// CLASS CanvasGraph
// Description: Creates a simple canvas graph which
// might be used together with the basic Image drawing
// primitives. Useful to auickoly produce some arbitrary
// graphic which benefits from all the functionality in the
// graph liek caching for example. 
//===================================================
class CanvasGraph extends Graph {
//---------------
// CONSTRUCTOR
    function CanvasGraph($aWidth=300,$aHeight=200,$aCachedName="",$timeout=0,$inline=1) {
	$this->Graph($aWidth,$aHeight,$aCachedName,$timeout,$inline);
    }

//---------------
// PUBLIC METHODS	

    function InitFrame() {
	$this->StrokePlotArea();
    }

    // Method description
    function Stroke($aStrokeFileName="") {
	if( $this->texts != null ) {
	    for($i=0; $i<count($this->texts); ++$i) {
		$this->texts[$i]->Stroke($this->img);
	    }
	}				
	$this->StrokeTitles();

	// Should we do any final image transformation
	if( $this->iImgTrans ) {
	    if( !class_exists('ImgTrans') ) {
		require_once('jpgraph_imgtrans.php');
	    }
	    
	    $tform = new ImgTrans($this->img->img);
	    $this->img->img = $tform->Skew3D($this->iImgTransHorizon,$this->iImgTransSkewDist,
					     $this->iImgTransDirection,$this->iImgTransHighQ,
					     $this->iImgTransMinSize,$this->iImgTransFillColor,
					     $this->iImgTransBorder);
	}
	

	// If the filename is given as the special _IMG_HANDLER
	// then the image handler is returned and the image is NOT
	// streamed back
	if( $aStrokeFileName == _IMG_HANDLER ) {
	    return $this->img->img;
	}
	else {
	    // Finally stream the generated picture					
	    $this->cache->PutAndStream($this->img,$this->cache_name,$this->inline,$aStrokeFileName);
	    return true;
	}
    }
} // Class
/* EOF */
?>