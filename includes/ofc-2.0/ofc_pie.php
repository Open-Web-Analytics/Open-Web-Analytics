<?php

class pie_value
{
	function pie_value( $value, $label )
	{
		$this->value = $value;
		$this->label = $label;
	}
	
	function set_colour( $colour )
	{
		$this->colour = $colour;
	}
	
	function set_label( $label, $label_colour, $font_size )
	{
		$this->label = $label;
		
		$tmp = 'label-colour';
		$this->$tmp = $label_colour;
		
		$tmp = 'font-size';
		$this->$tmp = $font_size;
		
	}
	
	function set_tooltip( $tip )
	{
		$this->tip = $tip;
	}
	
	function on_click( $event )
	{
		$tmp = 'on-click';
		$this->$tmp = $event;
	}

}

class pie
{
	function pie()
	{
		$this->type      		= 'pie';
		$this->colours     		= array("#d01f3c","#356aa0","#C79810");
		$this->border			= 2;
	}
	
	function set_colours( $colours )
	{
		$this->colours = $colours;
	}
	
	function set_alpha( $alpha )
	{
		$this->alpha = $alpha;
	}
	
	function set_values( $v )
	{
		$this->values = $v;		
	}
	
	// boolean
	function set_animate( $animate )
	{
		$this->animate = $animate;
	}
	
	// real
	function set_start_angle( $angle )
	{
		$tmp = 'start-angle';
		$this->$tmp = $angle;
	}
	
	function set_tooltip( $tip )
	{
		$this->tip = $tip;
	}
	
	function set_gradient_fill()
	{
		$tmp = 'gradient-fill';
		$this->$tmp = true;
	}
	
	function set_label_colour( $label_colour )
	{
		$tmp = 'label-colour';
		$this->$tmp = $label_colour;	
	}
	
	/**
	 * Turn off the labels
	 */
	function set_no_labels()
	{
		$tmp = 'no-labels';
		$this->$tmp = true;
	}
	
	function on_click( $event )
	{
		$tmp = 'on-click';
		$this->$tmp = $event;
	}
}
