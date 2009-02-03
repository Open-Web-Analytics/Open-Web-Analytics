package elements.axis {
	import flash.display.Sprite;
	import flash.geom.Matrix;
	import string.Utils;
	import charts.Elements.PointBar3D;
	import com.serialization.json.JSON;
	import Range;
	
	
	public class XAxis extends Sprite {

		private var steps:Number;
		private var alt_axis_colour:Number;
		private var alt_axis_step:Number;
		private var three_d:Boolean;
		private var three_d_height:Number;
		
		private var stroke:Number;
		private var tick_height:Number;
		private var colour:Number;
		public var offset:Boolean;
		private var grid_colour:Number;
		
		private var style:Object;
		
		function XAxis( json:Object )
		{
			// default values
			this.style = {
				stroke:			2,
				'tick-height':	3,
				colour:			'#784016',
				offset:			true,
				'grid-colour':	'#F5E1AA',
				'3d':			0,
				steps:			1,
				min:			0,
				max:			null
			};
			
			if( json != null )
				object_helper.merge_2( json, this.style );
			
			
			this.stroke = this.style.stroke;
			this.tick_height = this.style['tick-height'];
			this.colour = this.style.colour;
			// is the axis offset (see ScreenCoords)
			this.offset = this.style.offset;
			this.grid_colour = this.style.grid_colour;

			this.colour = Utils.get_colour( this.style.colour );
			this.grid_colour = Utils.get_colour( this.style['grid-colour'] );
			
				
			if( style['3d'] > 0 )
			{
				this.three_d = true;
				this.three_d_height = int( this.style['3d'] );
			}
			else
				this.three_d = false;

			// Patch from Will Henry
			if( json )
			{
				if( json.x_label_style != undefined ) {
					if( json.x_label_style.alt_axis_step != undefined )
						this.alt_axis_step = json.x_label_style.alt_axis_step;
						
					if( json.x_label_style.alt_axis_colour != undefined )
						this.alt_axis_colour = Utils.get_colour(json.x_label_style.alt_axis_colour);
				}
			}
			
			//
			// this is set later when the chart has more information
			//
			//this.grid_count = 1;
			
			// tick every X value?
			if ( this.style.steps == null )
				this.steps = 1;
			else
				this.steps = this.style.steps;
			
		}
		
		//
		// have we been passed a range? (min and max?)
		//
		public function range_set():Boolean {
			return this.style.max != null;
		}
		
		//
		// We don't have a range, so we need to calculate it.
		// grid lines, depends on number of values,
		// the X Axis labels and X min and X max
		//
		public function set_range( min:Number, max:Number ):void
		{
			this.style.min = min;
			this.style.max = max;
		}
		
		//
		// how many items in the X axis?
		//
		public function get_range():Range {
			return new Range( this.style.min, this.style.max, this.steps );
		}
		
		public function get_steps():Number {
			return this.steps;
		}
		
		public function resize( sc:ScreenCoords ):void
		{
			this.graphics.clear();
			
			//
			// Grid lines
			//
			for( var i:Number=this.style.min; i <= this.style.max; i+=this.steps )
			{
				if( ( this.alt_axis_step > 1 ) && ( i % this.alt_axis_step == 0 ) )
					this.graphics.beginFill(this.alt_axis_colour, 1);
				else
					this.graphics.beginFill(this.grid_colour, 1);
				
				var x:Number = sc.get_x_from_val(i);
				this.graphics.drawRect( x, sc.top, 1, sc.height );
				this.graphics.endFill();
			}
			
			if( this.three_d )
				this.three_d_axis( sc );
			else
				this.two_d_axis( sc );
		}
			
		public function three_d_axis( sc:ScreenCoords ):void
		{
			
			// for 3D
			var h:Number = this.three_d_height;
			var offset:Number = 12;
			var x_axis_height:Number = h+offset;
			
			//
			// ticks
			var item_width:Number = sc.width / this.style.max;
		
			// turn off out lines:
			this.graphics.lineStyle(0,0,0);
			
			var w:Number = 1;

			for( var i:Number=0; i < this.style.max; i+=this.steps )
			{
				var pos:Number = sc.get_x_tick_pos(i);
				
				this.graphics.beginFill(this.colour, 1);
				this.graphics.drawRect( pos, sc.bottom + x_axis_height, w, this.tick_height );
				this.graphics.endFill();
			}

			
			var lighter:Number = PointBar3D.Lighten( this.colour );
			
			// TOP
			var colors:Array = [this.colour,lighter];
			var alphas:Array = [100,100];
			var ratios:Array = [0,255];
		
			var matrix:Matrix = new Matrix();
			matrix.createGradientBox(sc.width_(), offset, (270 / 180) * Math.PI, sc.left-offset, sc.bottom );
			this.graphics.beginGradientFill('linear' /*GradientType.Linear*/, colors, alphas, ratios, matrix, 'pad'/*SpreadMethod.PAD*/ );
			this.graphics.moveTo(sc.left,sc.bottom);
			this.graphics.lineTo(sc.right,sc.bottom);
			this.graphics.lineTo(sc.right-offset,sc.bottom+offset);
			this.graphics.lineTo(sc.left-offset,sc.bottom+offset);
			this.graphics.endFill();
		
			// front
			colors = [this.colour,lighter];
			alphas = [100,100];
			ratios = [0, 255];
			
			matrix.createGradientBox( sc.width_(), h, (270 / 180) * Math.PI, sc.left - offset, sc.bottom + offset );
			this.graphics.beginGradientFill("linear", colors, alphas, ratios, matrix);
			this.graphics.moveTo(sc.left-offset,sc.bottom+offset);
			this.graphics.lineTo(sc.right-offset,sc.bottom+offset);
			this.graphics.lineTo(sc.right-offset,sc.bottom+offset+h);
			this.graphics.lineTo(sc.left-offset,sc.bottom+offset+h);
			this.graphics.endFill();
			
			// right side
			colors = [this.colour,lighter];
			alphas = [100,100];
			ratios = [0,255];
			
		//	var matrix:Object = { matrixType:"box", x:box.left - offset, y:box.bottom + offset, w:box.width_(), h:h, r:(225 / 180) * Math.PI };
			matrix.createGradientBox( sc.width_(), h, (225 / 180) * Math.PI, sc.left - offset, sc.bottom + offset );
			this.graphics.beginGradientFill("linear", colors, alphas, ratios, matrix);
			this.graphics.moveTo(sc.right,sc.bottom);
			this.graphics.lineTo(sc.right,sc.bottom+h);
			this.graphics.lineTo(sc.right-offset,sc.bottom+offset+h);
			this.graphics.lineTo(sc.right-offset,sc.bottom+offset);
			this.graphics.endFill();
			
		}
		
		// 2D:
		public function two_d_axis( sc:ScreenCoords ):void
		{
			//
			// ticks
			var item_width:Number = sc.width / this.style.max;
			var left:Number = sc.left+(item_width/2);
		
			//this.graphics.clear();
			// Axis line:
			this.graphics.lineStyle( 0, 0, 0 );
			this.graphics.beginFill( this.colour );
			this.graphics.drawRect( sc.left, sc.bottom, sc.width, this.stroke );
			this.graphics.endFill();
			
						
			tr.ace('gc');
			tr.ace(this.style.max);
			tr.ace(this.steps);
			
			for( var i:Number=this.style.min; i <= this.style.max; i+=this.steps )
			{
				var x:Number = sc.get_x_from_val(i);
				this.graphics.beginFill(this.colour, 1);
				this.graphics.drawRect( x-(this.stroke/2), sc.bottom + this.stroke, this.stroke, this.tick_height );
				this.graphics.endFill();
			}
		}
		
		public function height_():Number {
			return this.stroke + this.tick_height;
		}
		
		public function get_height():Number {
			if( this.three_d )
			{
				// 12 is the size of the slanty
				// 3D part of the X axis
				return this.three_d_height+12+this.tick_height;
			}
			else
				return this.stroke + this.tick_height;
		}
		
		public function die(): void {
			
			this.style = null;
		
			this.graphics.clear();
			while ( this.numChildren > 0 )
				this.removeChildAt(0);
		}
	}
}
