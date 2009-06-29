<?
/************************

A class to make heatmaps much easier.

 * @author Yvan Taviaud - LabsMedia - www.labsmedia.com
 * @author Zach Smith - ConnectedVentures - www.connectedventures.com
 * @author Peter Adams - Open Web Analytics - www.openwebanalytics.com
 * @since 27/10/2006
 * @updated 15/03/2007

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
	var $new_square;
	var $squares = array();
	var $squares_merge = array();
	var $square_width = 25;
	var $colors = array();
	var $height;
	var $width;
	var $grey = 140;
	var $high = 255;
	var $low = 0;
	var $alpha = 50;
	var $dot_width = 19;
	var $color_levels = array(50, 70, 90, 110, 120);
	var $max_height = 3000;
	var $max_width = 2000;	
	var $final_img;
	
	function heatmap($data = null) {
		
	
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
	
	
	/**
	 * Makes square regions out of an array of points.
	 * needed to limit color processing later on.
	 * 
	 */
	function makeSquares() {
		
		ksort($this->points);
				
		foreach ($this->points AS $x => $y_points) {
			
			//sort the y points if more than one
			if (!empty($y_points[1])):
				sort($y_points);
			endif;

			
			foreach ($y_points AS $y => $intensity) {
				
				
				$x2 = $x + $this->square_width/2;
				$x1 = $x - $this->square_width/2;
				$y2 = $y + $this->square_width/2;
				$y1 = $y - $this->square_width/2;
					
				$this->squares[$x1.$x2.$y1.$y2] = array($x1,$x2,$y1,$y2);
					
				$this->debug(sprintf("making square: x1=%s, x2=%s, y1=%s, y2=%s",
									$x1, $x2,$y1,$y2));
				
			}
			
		}
		
		return $this->mergeSquares();
	
	}
	
	/**
	 * Merges squares into larger squares so that color processing regions do not over lap.
	 * 
	 */
	function mergeSquares() {
		
		$this->debug('begining mergeSquares.');
		
		$this->squares_merge = &$this->squares;
		
		$this->debug('squares: '.print_r($this->squares_merge, true));
		
		$square_keys = array_keys($this->squares_merge);
		
		$this->debug('square_keys: '.print_r($square_keys, true));
		
		$i = 0;
		
		$ii = 0;
		
		foreach ($this->squares_merge as $k => $v) {
			
			$last_new_square = 0;
			$this->new_square = false;
			$i++;
			$this->debug('testing square: ' . $k);
			
			foreach ($square_keys as $square_index => $square_key) {	
			
				$this->debug('testing square_key: ' . $square_key);
				
				if ($k === $square_key):
					$this->debug('skipping test against myself.');
					continue;
				endif;
				
				$overlap_x = 0;
				$overlap_y = 0;
				// overlap tests
				if (($v[0] >= $this->squares_merge[$square_key][0]) && ($v[0] <= $this->squares_merge[$square_key][1])):
					$overlap_x++;
				endif;
				if (($v[1] >= $this->squares_merge[$square_key][0]) && ($v[1] <= $this->squares_merge[$square_key][1])):
					$overlap_x++;
				endif;
				if (($v[2] >= $this->squares_merge[$square_key][2]) && ($v[2] <= $this->squares_merge[$square_key][3])):
					$overlap_y++;
				endif;
				if (($v[3] >= $this->squares_merge[$square_key][2]) && ($v[3] <= $this->squares_merge[$square_key][3])):
					$overlap_y++;
				endif;
				
				if ($overlap_x >= 1 && $overlap_y >= 1 && $ii !=0):
					$this->debug(sprintf('Collision between %s and %s', $k, $square_key ));
					
					if ($v[0] < $this->squares_merge[$square_key][0]):
						$x1 = $v[0];
					else:
						$x1 = $this->squares_merge[$square_key][0];
					endif;
					
					if ($v[1] > $this->squares_merge[$square_key][1]):
						$x2 = $v[1];
					else:
						$x2 = $this->squares_merge[$square_key][1];
					endif;
					
					if ($v[2] < $this->squares_merge[$square_key][2]):
						$y1 = $v[2];
					else:
						$y1 = $this->squares_merge[$square_key][2];
					endif;
					
					if ($v[3] > $this->squares_merge[$square_key][3]):
						$y2 = $v[3];
					else:
						$y2 = $this->squares_merge[$square_key][3];
					endif;	
						
					// add new sqaure delete old ones
					
					//removing old keys from test array
					unset($square_keys[$square_index]);
					$key = array_search($k, $square_keys);
					$this->debug('removing square key: '.$key);
					unset($square_keys[$key]);
					$this->debug('removing square key: '.$square_index);
					
					
					
					// remove sqaures that have colided
					unset($this->squares_merge[$square_key]);
					$this->debug('removing square: '.$square_key);
					unset($this->squares_merge[$k]);
					$this->debug('removing square: '.$k);
					
					// add new square
					$this->squares_merge[$x1.$x2.$y1.$y2] = array($x1,$x2,$y1,$y2);
					$square_keys[] = $x1.$x2.$y1.$y2;
					$this->debug(sprintf('adding square: x1=%s, x2=%s, y1=%s, y2=%s', $x1,$x2,$y1,$y2));
					$this->new_square = true;	
					
					$this->debug(sprintf('Counts: Squares = %s, Test squares - %s',count($this->squares_merge), count($square_keys)));
					$this->debug('remaining square_keys: '.print_r($square_keys, true));
					$this->debug('remaining squares: '.print_r(array_keys($this->squares_merge), true));
					break;
				endif;	
				
				$ii++;
				
			}
			
		}
		
		$this->debug('Final Squares: '.print_r($this->squares, true));
		return;
		
	}
	
	
	function render($width, $height, $path = null) {
			
		//do a bit of init..
		$maxClicks = 1;
		
		// Server will run out of memory if you make an image too big.
		if ($height > $this->max_height):
			$height = $this->max_height;
		endif;
		
		if ($width > $this->max_width):
			$width = $this->max_width;
		endif;
		
		$this->height = $height;
		$this->width = $width;
		
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
		
		
		// make squares
		$this->makeSquares();
		
		$this->debug('squares: '.print_r($this->squares, true));
		
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
	  		
	  		$this->colors[$i] = $this->alpha * 16777216 + ceil($red) * 65536 + ceil($green) * 256 + ceil($blue);
	  	}
	  	
	  	//now combine the two images.
			$this->final_img = imagecreatetruecolor($width, $height);
			imagesavealpha($this->final_img, true);
			
			/** We don't use imagefill() because this function is buggy on the French host Free.fr */
			imagealphablending($this->final_img, false);
			imagefilledrectangle($this->final_img, 0, 0, $width - 1, $height - 1, 0x7FFFFFFF);
			imagealphablending($this->final_img, true);
	
			//$imgSrc = imagecreatefrompng($imagePath.'-'.$image.'.pngs');
			//unlink($imagePath.'-'.$image.'.pngs');
			foreach ($this->points AS $x => $y_points)
			{
				foreach ($y_points AS $y => $intensity)
				{
					$dot = (int) ceil(imagecolorat($imgSrc, $x, $y) / $maxClicks * 99);
					if ($dot !== 0)
					{
						imagecopy($this->final_img, $dots[$dot], ceil($x - $this->dot_width / 2), ceil($y - $this->dot_width / 2), 0, 0, $this->dot_width, $this->dot_width);
					}
				}
			}
			/** Destroy image source */
			imagedestroy($imgSrc);
			
			
			$this->colorizeSelect();
			
	
			/** Rainbow and maxClicks */
			if ($image === 0)
			{
				$white = imagecolorallocate($this->final_img, 255, 255, 255);
				$black = imagecolorallocate($this->final_img, 0, 0, 0);
				for ($i = 1; $i < 128; $i += 2)
				{
					imagefilledrectangle($this->final_img, $i/2 + 1, 0, $i/2 + 1, 10, $colors[$i]);
				}
				imagerectangle($this->final_img, 0, 0, 65, 11, $white);
				imagestring($this->final_img, 1, 1, 2, '0', $black);
				imagestring($this->final_img, 1, 65 - strlen($maxClicks) * 5, 2, $maxClicks, $black);
			}
			/** Save PNG file */
			if ($path === null)
			{
				header("Content-type: image/png");
				imagepng($this->final_img);
			}
			else
				imagepng($this->final_img, $path);
				
			imagedestroy($this->final_img);
	  	
	  	for ($i = 0; $i < 100; $i++)
	  	{
	  		imagedestroy($dots[$i]);
	  	}
	}
	
	function colorize() {
		
		/** Change the palette */
		imagealphablending($this->final_img, false);
		for ($x = 0; $x < $this->width; $x++)
		{
			for ($y = 0; $y < $this->height; $y++)
			{
				/** Set a pixel with the new color, while reading the current alpha level */
				imagesetpixel($this->final_img, $x, $y, $this->colors[127 - ((imagecolorat($this->final_img, $x, $y) & 0x7F000000) >> 24)]);
			}
		}	
		
		return;
	}
	
	function colorizeSelect() {
		
		$this->debug('grey: '. $this->colors[127 - ((imagecolorat($this->final_img, 1, 1) & 0x7F000000) >> 24)]);
		$grey = $this->colors[127 - ((imagecolorat($this->final_img, 1, 1) & 0x7F000000) >> 24)];
		
		foreach ($this->squares as $k => $v) {
			
			/** Change the palette */
			imagealphablending($this->final_img, false);
			for ($x = $v[0]; $x < $v[1]; $x++)
			{
				for ($y = $v[2]; $y < $v[3]; $y++)
				{
				
					/** Set a pixel with the new color, while reading the current alpha level */
					
					imagesetpixel($this->final_img, $x, $y, $this->colors[127 - ((imagecolorat($this->final_img, $x, $y)& 0x7F000000) >> 24)]);
				
				}
			}
		}
		
		imagefill($this->final_img, 0,0, $grey);	
	}
	
	function debug($msg) {
		
		return;
	}
}
?>