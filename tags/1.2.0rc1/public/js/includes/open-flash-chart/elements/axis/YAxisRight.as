package elements.axis {
	import flash.display.Sprite;
	
	public class YAxisRight extends YAxisBase {

		function YAxisRight( json:Object ) {
			
			super( json, 'y_axis_right' );
			
			//
			// OK, the user has set the right Y axis,
			// but forgot to specifically set visible to
			// true, I think we can forgive them:
			//
			if( json.y_axis_right )
				style.visible = true;
				
			this.labels = new YAxisLabelsRight( this, json );
			this.addChild( this.labels );
		}
		
		public override function get_style():Object {
			//
			// default values for a right axis (turned off)
			//
			var style:Object = {
				stroke:			2,
				'tick-length':	3,
				colour:			'#784016',
				offset:			false,
				'grid-colour':	'#F5E1AA',
				'3d':			0,
				steps:			1,
				visible:		false,
				min:			0,
				max:			10
			};
			
			return style;
		}
		
		public override function resize( label_pos:Number, sc:ScreenCoords ):void {
					
			if ( !this.style.visible )
				return;
			
			//
			// what if the user wants labes but no axis?
			//
			this.labels.resize( sc.right + this.stroke + this.tick_length, sc );
			
			this.graphics.clear();
			
			// Axis line:
			this.graphics.lineStyle( 0, 0, 0 );
			this.graphics.beginFill( this.colour, 1 );
			this.graphics.drawRect( sc.right, sc.top, this.stroke, sc.height );
			this.graphics.endFill();
//			return;
			

			// ticks..
			var min:Number = Math.min(this.style.min, this.style.max);
			var max:Number = Math.max(this.style.min, this.style.max);
			//var every:Number = (this.minmax.y2_max - this.minmax.y2_min) / this.steps;
			var left:Number = sc.right + this.stroke;
			var width:Number;
			for( var i:Number = min; i <= max; i+=this.style.steps ) {
				
				// start at the bottom and work up:
				var y:Number = sc.get_y_from_val(i, true);
				this.graphics.beginFill( this.colour, 1 );
				this.graphics.drawRect( left, y-(this.stroke/2), this.tick_length, this.stroke );
				this.graphics.endFill();
					
			}
			
		}
	}
}