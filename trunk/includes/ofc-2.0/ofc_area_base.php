<?php

class area_base
{
	function area_base()
	{
		$tmp = 'fill-alpha';
		$this->$tmp = 0.35;		
		$this->values    = array();	
	}
	
	function set_width( $w )
	{
		$this->width     = $w;
	}
	
	function set_colour( $colour )
	{
		$this->colour = $colour;
	}
	
	function set_fill_colour( $colour )
	{
		$this->fill = $colour;
	}
	
	function set_fill_alpha( $alpha )
	{
		$tmp = "fill-alpha";
		$this->$tmp = $alpha;
	}
	
	function set_halo_size( $size )
	{
		$tmp = 'halo-size';
		$this->$tmp = $size;
	}
	
	function set_values( $v )
	{
		$this->values = $v;		
	}
	
	function set_dot_size( $size )
	{
		$tmp = 'dot-size';
		$this->$tmp = $size;
	}

	function set_key( $text, $font_size )
	{
		$this->text      = $text;
		$tmp = 'font-size';
		$this->$tmp = $font_size;
	}
	
	function set_tooltip( $tip )
	{
		$this->tip = $tip;	
	}
	
	function set_loop()
	{
		$this->loop = true;
	}
}
