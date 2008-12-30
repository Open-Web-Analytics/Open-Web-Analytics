package charts {
	//import caurina.transitions.Tweener;

	import flash.display.Sprite;
	import flash.events.Event;
	import flash.events.MouseEvent;
	import charts.Elements.Element;
	import charts.Elements.PointDot;
	import string.Utils;
	import flash.display.BlendMode;
	
	public class LineDot extends LineBase
	{
		
		public function LineDot( json:Object )
		{
			
			this.style = {
				values:			[],
				width:			2,
				colour:			'#3030d0',
				text:			'',		// <-- default not display a key
				'dot-size':		5,
				'halo-size':	2,
				'font-size':	12,
				tip:			'#val#',
				'line-style':	new LineStyle( json['line-style'] )
			};
			
			object_helper.merge_2( json, style );
			
			this.style.colour = string.Utils.get_colour( this.style.colour );
			
			this.key = style.text;
			this.font_size = style['font-size'];
			
//			this.axis = which_axis_am_i_attached_to(data, num);
//			tr.ace( name );
//			tr.ace( 'axis : ' + this.axis );
				
			this.values = style['values'];
			this.add_values();
			
			//
			// this allows the dots to erase part of the line
			//
			this.blendMode = BlendMode.LAYER;
			
		}
		
		
		//
		// called from the BaseLine object
		//
		protected override function get_element( index:Number, value:Object ): Element {

			var s:Object = this.merge_us_with_value_object( value );
			return new PointDot( index, s );
		}
	}
}