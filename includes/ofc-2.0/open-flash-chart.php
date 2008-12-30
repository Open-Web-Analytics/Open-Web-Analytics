<?php

// var_dump(debug_backtrace());

//
// Omar Kilani's php C extension for encoding JSON has been incorporated in stock PHP since 5.2.0
// http://www.aurore.net/projects/php-json/
//
// -- Marcus Engene
//
if (! function_exists('json_encode'))
{
	include_once 'JSON.php';
}

include_once 'json_format.php';

// ofc classes
include_once 'ofc_title.php';
include_once 'ofc_y_axis_base.php';
include_once 'ofc_y_axis.php';
include_once 'ofc_y_axis_right.php';
include_once 'ofc_x_axis.php';
include_once 'ofc_area_base.php';
include_once 'ofc_area_hollow.php';
include_once 'ofc_area_line.php';
include_once 'ofc_pie.php';
include_once 'ofc_bar.php';
include_once 'ofc_bar_filled.php';
include_once 'ofc_bar_glass.php';
include_once 'ofc_bar_stack.php';
include_once 'ofc_bar_3d.php';
include_once 'ofc_hbar.php';
include_once 'ofc_line_base.php';
include_once 'ofc_line.php';
include_once 'ofc_line_dot.php';
include_once 'ofc_line_hollow.php';
include_once 'ofc_x_legend.php';
include_once 'ofc_y_legend.php';
include_once 'ofc_bar_sketch.php';
include_once 'ofc_scatter.php';
include_once 'ofc_scatter_line.php';
include_once 'ofc_x_axis_labels.php';
include_once 'ofc_x_axis_label.php';
include_once 'ofc_tooltip.php';
include_once 'ofc_shape.php';
include_once 'ofc_radar_axis.php';
include_once 'ofc_radar_axis_labels.php';
include_once 'ofc_radar_spoke_labels.php';
include_once 'ofc_line_style.php';

class open_flash_chart
{
	function open_flash_chart()
	{
		//$this->title = new title( "Many data lines" );
		$this->elements = array();
	}
	
	function set_title( $t )
	{
		$this->title = $t;
	}
	
	function set_x_axis( $x )
	{
		$this->x_axis = $x;	
	}
	
	function set_y_axis( $y )
	{
		$this->y_axis = $y;
	}
	
	function add_y_axis( $y )
	{
		$this->y_axis = $y;
	}

	function set_y_axis_right( $y )
	{
		$this->y_axis_right = $y;
	}
	
	function add_element( $e )
	{
		$this->elements[] = $e;
	}
	
	function set_x_legend( $x )
	{
		$this->x_legend = $x;
	}

	function set_y_legend( $y )
	{
		$this->y_legend = $y;
	}
	
	function set_bg_colour( $colour )
	{
		$this->bg_colour = $colour;	
	}
	
	function set_radar_axis( $radar )
	{
		$this->radar_axis = $radar;
	}
	
	function set_tooltip( $tooltip )
	{
		$this->tooltip = $tooltip;	
	}
	
	function toString()
	{
		if (function_exists('json_encode'))
		{
			return json_encode($this);
		}
		else
		{
			$json = new Services_JSON();
			return $json->encode( $this );
		}
	}
	
	function toPrettyString()
	{
		return json_format( $this->toString() );
	}
}



//
// there is no PHP end tag so we don't mess the headers up!
//