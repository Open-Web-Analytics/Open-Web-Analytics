package charts {
	
	import charts.Elements.PointScatter;
	import charts.Elements.Element;
	import string.Utils;
	import flash.geom.Point;
	
	public class ScatterBase extends Base {

		// TODO: move this into Base
		protected var style:Object;
		
		public function ScatterBase() { }
		
		//
		// called from the base object
		//
		protected override function get_element( index:Number, value:Object ): Element {
			// we ignore the X value (index) passed to us,
			// the user has provided their own x value
			
			var default_style:Object = {
				'dot-size':		this.style['dot-size'],
				width:			this.style.width,	// stroke
				colour:			this.style.colour,
				tip:			this.style.tip
			};
			
			object_helper.merge_2( value, default_style );
				
			// our parent colour is a number, but
			// we may have our own colour:
			if( default_style.colour is String )
				default_style.colour = Utils.get_colour( default_style.colour );
			
			return new PointScatter( default_style );
		}
		
		// Draw points...
		public override function resize( sc:ScreenCoordsBase ): void {
			
			for ( var i:Number = 0; i < this.numChildren; i++ ) {
				var e:PointScatter = this.getChildAt(i) as PointScatter;
				e.resize( sc, this.axis );
			}
		}
		
		//
		// scatter charts can have many items at the same Y position
		// so we need to figure out which one to pass back
		//
		public function ___OLD___closest_2( x:Number, y:Number ): Object {
			
			var shortest:Number = Number.MAX_VALUE;
			var dx:Number;
			var x_pos:Number;
			var i:Number;
			var e:Element;
			var p:flash.geom.Point;
			
			//
			// get shortest distance along X
			//
			

			var tmp:Array = new Array();
			
			for( i=0; i < this.numChildren; i++ ) {
			
				// some of the children will will mask
				// Sprites, so filter those out:
				//
				if( this.getChildAt(i) is Element ) {
		
					e = this.getChildAt(i) as Element;
				
					p = e.get_mid_point();
					if ( p.x == x_pos )
						tmp.push( e );
				}
			}
			
			var y_min:Number = Number.MAX_VALUE;
			var closest:Element = tmp[0];
			var dy:Number;
			
			for each( e in tmp ) {
				
				p = e.get_mid_point();
				dy = Math.abs( y - p.y );
				
				if ( dy < y_min )
				{
					closest = e;
					y_min = dy;
				}
			}
			
			//
			// TODO: this is now done in tooltip
			//
			for ( i=0; i < this.numChildren; i++ ) {
			
				if( this.getChildAt(i) is Element ) {
		
					e = this.getChildAt(i) as Element;
					if ( e != closest )
						e.set_tip( false );
				}
			}
		
			if( closest )
				dy = Math.abs( y - closest.y );
				
			return { element:closest, distance_x:shortest, distance_y:dy };
		}
	}
}