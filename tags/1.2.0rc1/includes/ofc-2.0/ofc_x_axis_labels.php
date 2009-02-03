<?php

class x_axis_labels
{
	function x_axis_labels(){}
	
	function set_steps( $steps )
	{
		$this->steps = $steps;
	}
	
	//
	// An array of [x_axis_label or string]
	//
	function set_labels( $labels )
	{
		$this->labels = $labels;
	}
	
	function set_colour( $colour )
	{
		$this->colour = $colour;
	}
	
	function set_size( $size )
	{
		$this->size = $size;
	}
	
	function set_vertical()
	{
		$this->rotate = "vertical";
	}
}