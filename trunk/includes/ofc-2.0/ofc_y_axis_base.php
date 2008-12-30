<?php

class y_axis_base
{
	function y_axis_base(){}
	
	function set_stroke( $s )
	{
		$this->stroke = $s;
	}
	
	function set_tick_length( $val )
	{
		$tmp = 'tick-length';
		$this->$tmp = $val;
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
	
	function set_grid_colour( $colour )
	{
		$tmp = 'grid-colour';
		$this->$tmp = $colour;
	}
	
	function set_range( $min, $max, $steps=1 )
	{
		$this->min = $min;
		$this->max = $max;
		$this->set_steps( $steps );
	}
	
	function set_offset( $off )
	{
		$this->offset = $off?1:0;
	}
	
	function set_labels( $labels )
	{
		$this->labels = $labels;	
	}
	
	function set_steps( $steps )
	{
		$this->steps = $steps;	
	}
}