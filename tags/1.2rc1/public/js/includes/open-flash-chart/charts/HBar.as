package charts {
	import charts.Elements.Element;
	import charts.Elements.PointHBar;
	import string.Utils;
	import global.Global;
	
	public class HBar extends Base {
		
		protected var group:Number;
		protected var style:Object;
		
		public function HBar( json:Object ) {
			
			this.style = {
				values:				[],
				colour:				'#3030d0',
				text:				'',		// <-- default not display a key
				'font-size':		12,
				tip:				'#val#'
			};
			
			object_helper.merge_2( json, style );
			
			//this.alpha = Number( vals[0] );
			this.colour = string.Utils.get_colour( style.colour );
			this.key = json.text;
			this.font_size = json['font-size'];
			
			// this.axis = which_axis_am_i_attached_to(data, num);
			
			//
			// bars are grouped, so 3 bar sets on one chart
			// will arrange them selves next to each other
			// at each value of X, this.group tell the bar
			// where it is in that grouping
			//
			this.group = 0;
			
			this.values = json['values'];
			
			this.add_values();
		}
		
		//
		// called from the base object, in this case the
		// value is the X value of the bar and the index
		// is the Y positiont
		//
		protected override function get_element( index:Number, value:Object ): Element {
			
			var default_style:Object = {
					colour:		this.style.colour,
					tip:		this.style.tip
			};
			
			if( value is Number )
				default_style.top = value;
			else
				object_helper.merge_2( value, default_style );
				
			// our parent colour is a number, but
			// we may have our own colour:
			if( default_style.colour is String )
				default_style.colour = Utils.get_colour( default_style.colour );
			
			return new PointHBar( index, default_style, this.group );
		}
		
		public override function resize( sc:ScreenCoordsBase ): void {
			
			for ( var i:Number = 0; i < this.numChildren; i++ )
			{
				var p:PointHBar = this.getChildAt(i) as PointHBar;
				p.resize( sc, this.axis );
			}
		}
		
		public override function get_max_x_value():Number {
			
			var x:Number = 0;
			//
			// count the non-mask items:
			//
			for ( var i:Number = 0; i < this.numChildren; i++ )
				if( this.getChildAt(i) is PointHBar ) {
					
					var h:PointHBar = this.getChildAt(i) as PointHBar;
					x = Math.max( x, h.get_max_x_value() );
					
				}
	
			return x;
		}

	}
}