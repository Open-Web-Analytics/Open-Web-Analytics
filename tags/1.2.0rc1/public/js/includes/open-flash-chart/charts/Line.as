package charts {

	import flash.events.Event;
	import flash.events.MouseEvent;
	import charts.Elements.Element;
	import charts.Elements.Point;
	import string.Utils;
	import flash.display.BlendMode;
	
	public class Line extends LineBase
	{
		
		public function Line( json:Object ) {
		
			this.style = {
				values: 		[],
				width:			2,
				colour: 		'#3030d0',
				text: 			'',		// <-- default not display a key
				'dot-size': 	5,
				'halo-size':	2,
				'font-size': 	12,
				tip:			'#val#',
				'line-style':	new LineStyle( json['line-style'] ),
				'on-click':		null,
				loop:			false
			};
			
			object_helper.merge_2( json, this.style );
			
			this.style.colour = string.Utils.get_colour( this.style.colour );
			
			this.key		= this.style.text;
			this.font_size	= this.style['font-size'];
				
			this.values = this.style.values;
			this.add_values();

			//
			// this allows the dots to erase part of the line
			//
			this.blendMode = BlendMode.LAYER;
			
		}
		

		//
		// called from the base object
		//
		protected override function get_element( index:Number, value:Object ): Element {
			
			var s:Object = this.merge_us_with_value_object( value );

			return new charts.Elements.Point( index, s );
		}
	}
}