<?php

include_once 'ofc_bar_base.php';

class bar_sketch extends bar_base
{
	function bar_sketch( $colour, $outline_colour, $fun_factor )
	{
		$this->type      = "bar_sketch";
		parent::bar_base();
		
		$this->set_colour( $colour );
		$this->set_outline_colour( $outline_colour );
		$this->offset = $fun_factor;
	}
	
	function set_outline_colour( $outline_colour )
	{
		$tmp = 'outline-colour';
		$this->$tmp = $outline_colour;	
	}
}

