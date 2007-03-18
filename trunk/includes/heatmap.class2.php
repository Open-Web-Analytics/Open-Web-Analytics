<?
/************************

A class to make heatmaps much easier.

 * @author Yvan Taviaud - LabsMedia - www.labsmedia.com
 * @author Zach Smith - ConnectedVentures - www.connectedventures.com
 * @since 27/10/2006
 * @updated 05/02/2007

example (random generated heat map):

		$max = 300;
		
		//make 3000 random data points.
		$data = array();
    for ($i=0; $i<3000; $i++)
    {
    	$x = mt_rand(0, $max);
    	$y = mt_rand(0, $max);
    	
    	$data[$x][$y]++;
    }
    
    //var_dump($data);
    
    //draw the heatmap
    $map = new heatmap($data);
    $map->render($max, $max)

*************************/
class heatmap {
	
	var $points;
	var $grey = 140;
	var $high = 255;
	var $low = 0;
	var $alpha = 50;
	var $dot_width = 19;
	var $color_levels = array(50, 70, 90, 110, 120);
	var $max_height = 3000;
	var $max_width = 2000;	
	function heatmap($data = null)
	{
		if ($data !== null):
			$this->load_points($data);
		endif;
		
		return;
	}
	
	function load_points($data)
	{
		$this->points = $data;
		return;
	}
	
	function render($width, $height, $path = null)
	{
		//do a bit of init..
		$maxClicks = 1;
		
		// Server will run out of memory if you make an image too big.
		if ($height > $this->max_height):
			$height = $this->max_height;
		endif;
		
		if ($width > $this->max_width):
			$width = $this->max_width;
		endif;
		
		//create our image
		$imgSrc = imagecreatetruecolor($width, $height);
		imagefill($imgSrc, 0, 0, 0);
		
		//increment the color at each location...
		foreach ($this->points AS $x => $y_points)
		{
			foreach ($y_points AS $y => $intensity)
			{
				$color = imagecolorat($imgSrc, $x, $y) + $intensity;
				imagesetpixel($imgSrc, $x, $y, $color);
				
				$maxClicks = max($maxClicks, $color);
			}
		}
		
		//not really sure what this is for...?  some sort of blending palette dealie
	  	for ($i = 0; $i < 100; $i++)
	  	{
	  		$dots[$i] = imagecreatetruecolor($this->dot_width, $this->dot_width);
	  		imagealphablending($dots[$i], false);
	  	}
	  	
	  	
	  	for ($x = 0; $x < $this->dot_width; $x++)
	  	{
	  		for ($y = 0; $y < $this->dot_width; $y++)
	  		{
	  			$sinX = sin($x * pi() / $this->dot_width);
	  			$sinY = sin($y * pi() / $this->dot_width);
	  			for ($i = 0; $i < 100; $i++)
	  			{
	  				/** Alpha range is only 27 => 127 to limit the effect on nearby pixels */
	  				$alpha = 127 - $i * $sinX * $sinY * $sinX * $sinY;
	  				imagesetpixel($dots[$i], $x, $y, ((int) $alpha) * 16777216);
	  			}
	  		}
	  	}
	  	/**
	  	 * Colors creation :
	  	 * grey	=> deep blue (rgB)	=> light blue (rGB)	=> green (rGb)		=> yellow (RGb)		=> red (Rgb)
	  	 * 0	   $this->color_levels[0]	   $this->color_levels[1]	   $this->color_levels[2]	   $this->color_levels[3]	   128
	  	**/
	  	sort($this->color_levels);
	  	for ($i = 0; $i < 128; $i++)
	  	{
	  		/** Red */
	  		if ($i < $this->color_levels[0])
	  		{
	  			$red = $this->grey + ($this->low - $this->grey) * $i / $this->color_levels[0];
	  		}
	  		elseif ($i < $this->color_levels[2])
	  		{
	  			$red = $this->low;
	  		}
	  		elseif ($i < $this->color_levels[3])
	  		{
	  			$red = $this->low + ($this->high - $this->low) * ($i - $this->color_levels[2]) / ($this->color_levels[3] - $this->color_levels[2]);
	  		}
	  		else
	  		{
	  			$red = $this->high;
	  		}
	  		
	  		/** Green */
	  		if ($i < $this->color_levels[0])
	  		{
	  			$green = $this->grey + ($this->low - $this->grey) * $i / $this->color_levels[0];
	  		}
	  		elseif ($i < $this->color_levels[1])
	  		{
	  			$green = $this->low + ($this->high - $this->low) * ($i - $this->color_levels[0]) / ($this->color_levels[1] - $this->color_levels[0]);
	  		}
	  		elseif ($i < $this->color_levels[3])
	  		{
	  			$green = $this->high;
	  		}
	  		else
	  		{
	  			$green = $this->high - ($this->high - $this->low) * ($i - $this->color_levels[3]) / (127 - $this->color_levels[3]);
	  		}
	  		
	  		/** Blue */
	  		if ($i < $this->color_levels[0])
	  		{
	  			$blue = $this->grey + ($this->high - $this->grey) * $i / $this->color_levels[0];
	  		}
	  		elseif ($i < $this->color_levels[1])
	  		{
	  			$blue = $this->high;
	  		}
	  		elseif ($i < $this->color_levels[2])
	  		{
	  			$blue = $this->high - ($this->high - $this->low) * ($i - $this->color_levels[1]) / ($this->color_levels[2] - $this->color_levels[1]);
	  		}
	  		else
	  		{
	  			$blue = $this->low;
	  		}
	  		
	  		$colors[$i] = $this->alpha * 16777216 + ceil($red) * 65536 + ceil($green) * 256 + ceil($blue);
	  	}
	  	
	  	//now combine the two images.
			$img = imagecreatetruecolor($width, $height);
			imagesavealpha($img, true);
			
			/** We don't use imagefill() because this function is buggy on the French host Free.fr */
			imagealphablending($img, false);
			imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, 0x7FFFFFFF);
			imagealphablending($img, true);
	
			//$imgSrc = imagecreatefrompng($imagePath.'-'.$image.'.pngs');
			//unlink($imagePath.'-'.$image.'.pngs');
			for ($x = 0; $x < $width; $x++)
			{
				for ($y = 0; $y < $height; $y++)
				{
					$dot = (int) ceil(imagecolorat($imgSrc, $x, $y) / $maxClicks * 99);
					if ($dot !== 0)
					{
						imagecopy($img, $dots[$dot], ceil($x - $this->dot_width / 2), ceil($y - $this->dot_width / 2), 0, 0, $this->dot_width, $this->dot_width);
					}
				}
			}
			/** Destroy image source */
			imagedestroy($imgSrc);
	
			/** Change the palette */
			imagealphablending($img, false);
			for ($x = 0; $x < $width; $x++)
			{
				for ($y = 0; $y < $height; $y++)
				{
					/** Set a pixel with the new color, while reading the current alpha level */
					imagesetpixel($img, $x, $y, $colors[127 - ((imagecolorat($img, $x, $y) & 0x7F000000) >> 24)]);
				}
			}
	
			/** Rainbow and maxClicks */
			if ($image === 0)
			{
				$white = imagecolorallocate($img, 255, 255, 255);
				$black = imagecolorallocate($img, 0, 0, 0);
				for ($i = 1; $i < 128; $i += 2)
				{
					imagefilledrectangle($img, $i/2 + 1, 0, $i/2 + 1, 10, $colors[$i]);
				}
				imagerectangle($img, 0, 0, 65, 11, $white);
				imagestring($img, 1, 1, 2, '0', $black);
				imagestring($img, 1, 65 - strlen($maxClicks) * 5, 2, $maxClicks, $black);
			}
			/** Save PNG file */
			if ($path === null)
			{
				header("Content-type: image/png");
				imagepng($img);
			}
			else
				imagepng($img, $path);
				
			imagedestroy($img);
	  	
	  	for ($i = 0; $i < 100; $i++)
	  	{
	  		imagedestroy($dots[$i]);
	  	}
	}
}
?>