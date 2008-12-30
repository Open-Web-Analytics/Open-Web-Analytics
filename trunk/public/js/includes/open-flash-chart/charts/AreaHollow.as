package charts {
	import charts.Elements.Element;
	import charts.Elements.PointHollow;
	import string.Utils;
	import flash.display.BlendMode;
	
	public class AreaHollow extends AreaBase {
		
		public function AreaHollow( json:Object ) {
			
			super( json );
		}
		
		//
		// called from the base object
		//
		protected override function get_element( index:Number, value:Object ): Element {
			
			//
			// WARNING: this is copied from LineHollow, THIS MUST BE
			//          AN EXACT COPY OF THAT CODE (yes this is a 
			//          bit hacky)
			//
			
			var s:Object = this.merge_us_with_value_object( value );
			//
			// the width of the hollow circle is the same as the width of the line
			//
			s.width = this.style.width;
			
			return new charts.Elements.PointHollow( index, s );
		}
	}
}