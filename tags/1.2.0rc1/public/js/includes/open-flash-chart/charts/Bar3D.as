package charts {
	import charts.Elements.Element;
	import charts.Elements.PointBar3D;
	import string.Utils;
	
	
	public class Bar3D extends BarBase {
		
		public function Bar3D( json:Object, group:Number ) {
			super( json, group );
		}
		
		//
		// called from the base object
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
				
			
			return new PointBar3D( index, default_style, this.group );
		}
	}
}