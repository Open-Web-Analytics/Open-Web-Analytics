package elements.axis {
	import flash.display.Sprite;
	
	public class YAxisLeft extends YAxisBase {

		function YAxisLeft( json:Object ) {
			
			super( json, 'y_axis' );
			
			this.labels = new YAxisLabelsLeft( this, json );
			this.addChild( this.labels );
		}
		
		public override function get_style():Object {
			//
			// default values for a left axis
			//
			var style:Object = {
				stroke:			2,
				'tick-length':	3,
				colour:			'#784016',
				offset:			false,
				'grid-colour':	'#F5E1AA',
				'3d':			0,
				steps:			1,
				visible:		true,
				min:			0,
				max:			10
			};
			
			/*
			$maxValue = max($bar_1->data) * 1.07;
			$l = round(log($maxValue)/log(10));
			$p = pow(10, $l) / 2;
			$maxValue = round($maxValue * 1.1 / $p) * $p;
			*/
			
			return style;
		}
		
		public override function resize( label_pos:Number, sc:ScreenCoords ):void {
			
			this.labels.resize( label_pos, sc );
			
			if ( !this.style.visible )
				return;
			
			this.graphics.clear();
			this.graphics.lineStyle( 0, 0, 0 );
			
			// y axel grid lines
			//var every:Number = (this.minmax.y_max - this.minmax.y_min) / this.steps;
			
			// Set opacity for the first line to 0 (otherwise it overlaps the x-axel line)
			//
			// Bug? Does this work on graphs with minus values?
			//
			var i2:Number = 0;
			var i:Number;
			var y:Number;
			
			var min:Number = Math.min(this.style.min, this.style.max);
			var max:Number = Math.max(this.style.min, this.style.max);
			
			//
			// hack: http://kb.adobe.com/selfservice/viewContent.do?externalId=tn_13989&sliceId=1
			//
			max += 0.000004;
			
			for( i = min; i <= max; i+=this.style.steps ) {
				
				y = sc.get_y_from_val(i);
				this.graphics.beginFill( this.grid_colour, 1 );
				this.graphics.drawRect( sc.left, y, sc.width, 1 );
				this.graphics.endFill();
			}
			var left:Number = sc.left - this.stroke;
			
			// Axis line:
			this.graphics.beginFill( this.colour, 1 );
			this.graphics.drawRect( left, sc.top, this.stroke, sc.height );
			this.graphics.endFill();
			
			// ticks..
			var width:Number;
			for( i = min; i <= max; i+=this.style.steps ) {
				
				// start at the bottom and work up:
				y = sc.get_y_from_val(i, false);
				
				this.graphics.beginFill( this.colour, 1 );
				this.graphics.drawRect( left-this.tick_length, y-(this.stroke/2), this.tick_length, this.stroke );
				this.graphics.endFill();
					
			}
		}
	}
}