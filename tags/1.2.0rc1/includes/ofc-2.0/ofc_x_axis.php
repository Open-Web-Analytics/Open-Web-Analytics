<?php

class x_axis
{
	function x_axis(){}

	function set_stroke( $stroke )
	{
		$this->stroke = $stroke;	
	}
	
	function set_colours( $colour, $grid_colour )
	{
		$this->set_colour( $colour );
		$this->set_grid_colour( $grid_colour );
	}
	
	function set_colour( $colour )
	{
		$this->colour = $colour;	
	}
	
	function set_tick_height( $height )
	{
		$tmp = 'tick-height';
		$this->$tmp      		= $height;
	}
	
	function set_grid_colour( $colour )
	{
		$tmp = 'grid-colour';
		$this->$tmp = $colour;
	}
	
	// $o is a boolean
	function set_offset( $o )
	{
		$this->offset = $o?true:false;	
	}
	
	function set_steps( $steps )
	{
		$this->steps = $steps;
	}
	
	function set_3d( $val )
	{
		$tmp = '3d';
		$this->$tmp				= $val;		
	}
	
	function set_labels( $x_axis_labels )
	{
		//$this->labels = $v;
		$this->labels = $x_axis_labels;
	}
	
	//
	// helper function to make the examples
	// simpler.
	//
	function set_labels_from_array( $a )
	{
		$x_axis_labels = new x_axis_labels();
		$x_axis_labels->set_labels( $a );
		$this->labels = $x_axis_labels;
		
		if( isset( $this->steps ) )
			$x_axis_labels->set_steps( $this->steps );
	}
	
	function set_range( $min, $max )
	{
		$this->min = $min;
		$this->max = $max;
	}
}