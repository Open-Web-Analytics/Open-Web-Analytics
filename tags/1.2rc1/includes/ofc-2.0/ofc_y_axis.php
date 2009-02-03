<?php

class y_axis extends y_axis_base
{
	function y_axis(){}
	
	//
	// y axis right does NOT control
	// grid colour, the left axis does
	//
	function set_grid_colour( $colour )
	{
		$tmp = 'grid-colour';
		$this->$tmp = $colour;
	}
	
}