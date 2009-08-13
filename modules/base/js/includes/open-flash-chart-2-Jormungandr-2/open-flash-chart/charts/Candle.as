package charts {
	import charts.series.Element;
	import charts.series.bars.ECandle;
	import string.Utils;

	
	public class Candle extends BarBase {
		
		public function Candle( json:Object, group:Number ) {
			
			super( json, group );
		}
		
		//
		// called from the base object
		//
		protected override function get_element( index:Number, value:Object ): Element {
			
			return new ECandle( index, this.get_element_helper_prop( value ), this.group );
		}
	}
}