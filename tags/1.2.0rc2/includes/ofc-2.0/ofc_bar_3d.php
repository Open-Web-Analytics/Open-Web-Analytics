<?php

include_once 'ofc_bar_base.php';

class bar_3d_value
{
	function bar_3d_value( $top )
	{
		$this->top = $top;
//		$this->bottom = $bottom;
	}
	
	function set_colour( $colour )
	{
		$this->colour = $colour;
	}
	
	function set_tooltip( $tip )
	{
		$this->tip = $tip;
	}
}

class bar_3d extends bar_base
{
	function bar_3d()
	{
		$this->type      = "bar_3d";
		parent::bar_base();
	}
}