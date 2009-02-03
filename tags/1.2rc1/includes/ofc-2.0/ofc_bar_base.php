<?php

/* this is a base class */

class bar_base
{
	function bar_base(){}

	function set_key( $text, $size )
	{
		$this->text = $text;
		$tmp = 'font-size';
		$this->$tmp = $size;
	}

	function set_values( $v )
	{
		$this->values = $v;		
	}
	
	function append_value( $v )
	{
		$this->values[] = $v;		
	}
	
	function set_colour( $colour )
	{
		$this->colour = $colour;	
	}

	function set_alpha( $alpha )
	{
		$this->alpha = $alpha;	
	}
	
	function set_tooltip( $tip )
	{
		$this->tip = $tip;	
	}
}

