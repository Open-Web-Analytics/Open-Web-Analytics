package charts {
	import charts.Elements.Element;
	import charts.Elements.PointBarSketch;
	import string.Utils;
	
	public class BarSketch extends BarBase {
		private var outline_colour:Number;
		private var offset:Number;
		
		public function BarSketch( json:Object, group:Number ) {
			
			//
			// these are specific values to the Sketch
			// and so we need to sort them out here
			//
			var style:Object = {
				'outline-colour':	"#000000",
				offset:				3
			};
			
			object_helper.merge_2( json, style );
			
			super( style, group );
		}
		
/*
 *
 * FIX THIS::
 *
		public override function parse_bar( json:Object ):void {
			var style:Object = {
				values:				[],
				colour:				'#3030d0',
				'outline-colour':	"#000000",
				text:				'',		// <-- default not display a key
				'font-size':		12,
				offset:				3,
				width:				2
			};
			
			object_helper.merge_2( json, style );
			
			this.line_width = style.width;
			this.colour		= string.Utils.get_colour( style.colour );
			this.outline_colour = string.Utils.get_colour( style['outline-colour'] );
			this.key		= style.text;
			this.font_size	= style['font-size'];
			this.offset     = style.offset;
		}
*/
		
		//
		// called from the base object
		//
		protected override function get_element( index:Number, value:Object ): Element {
			
			var default_style:Object = {
					colour:				this.style.colour,
					tip:				this.style.tip,
					offset:				this.style.offset,
					'outline-colour':	this.style['outline-colour']
			};
					
			if( value is Number )
				default_style.top = value;
			else
				object_helper.merge_2( value, default_style );
				
			// our parent colour is a number, but
			// we may have our own colour:
			if( default_style.colour is String )
				default_style.colour = Utils.get_colour( default_style.colour );
				
			if( default_style['outline-colour'] is String )
				default_style['outline-colour'] = Utils.get_colour( default_style['outline-colour'] );
				
			return new PointBarSketch( index, default_style, this.group );
		}
	}
}