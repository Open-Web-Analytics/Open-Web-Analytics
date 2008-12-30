<?php

class scatter_value
{
	function scatter_value( $x, $y, $dot_size=-1 )
	{
		$this->x = $x;
		$this->y = $y;
		
		if( $dot_size > 0 )
		{
			$tmp = 'dot-size';
			$this->$tmp = $dot_size;
		}
	}
}

class scatter
{
	function scatter( $colour, $dot_size )
	{
		$this->type      = "scatter";
		$this->set_colour( $colour );
		$this->set_dot_size( $dot_size );
	}
	
	function set_colour( $colour )
	{
		$this->colour = $colour;
	}

	function set_dot_size( $dot_size )
	{
		$tmp = 'dot-size';
		$this->$tmp = $dot_size;
	}
	
	function set_values( $values )
	{
		$this->values = $values;
	}
}
