<?php

include_once 'ofc_bar_base.php';


class bar_glass_value
{
	function bar_glass_value( $top )
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


class bar_glass extends bar_base
{
	function bar_glass()
	{
		$this->type      = "bar_glass";
		parent::bar_base();
	}
}
