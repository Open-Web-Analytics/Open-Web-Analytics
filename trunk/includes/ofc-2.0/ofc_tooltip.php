<?php

include_once 'ofc_bar_base.php';

class tooltip
{
	function tooltip(){}
	
	function set_shadow( $shadow )
	{
		$this->shadow = $shadow;
	}
	
	// stroke in pixels (e.g. 5 )
	function set_stroke( $stroke )
	{
		$this->stroke = $stroke;
	}
	
	function set_colour( $colour )
	{
		$this->colour = $colour;
	}
	
	function set_background_colour( $bg )
	{
		$this->background = $bg;
	}
	
	// a css style
	function set_title_style( $style )
	{
		$this->title = $style;
	}
	
    function set_body_style( $style )
	{
		$this->body = $style;
	}
	
	function set_proximity()
	{
		$this->mouse = 1;
	}
	
	function set_hover()
	{
		$this->mouse = 2;
	}
}

