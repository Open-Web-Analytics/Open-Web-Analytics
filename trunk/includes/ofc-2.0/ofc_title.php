<?php

class title
{
	function title( $text='' )
	{
		$this->text = $text;
	}
	
	function set_style( $css )
	{
		$this->style = $css;
		//"{font-size: 20px; color:#0000ff; font-family: Verdana; text-align: center;}";		
	}
}