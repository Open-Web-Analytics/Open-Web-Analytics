package charts {
	import charts.Elements.Element;
	import charts.series.bars.StackCollection;
	import string.Utils;
	import com.serialization.json.JSON;
	import flash.geom.Point;
	
	
	public class BarStack extends BarBase {
		
		public function BarStack( json:Object, num:Number, group:Number ) {
			
			super(null, 0);
			
			this.style = {
				colours:			['#FF0000','#00FF00'],	// <-- ugly default colours
				values:				[],
				keys:				[],
				tip:				'#x_label# : #val#<br>Total: #total#'
			};
			
			object_helper.merge_2( json, style );
			
//			this.axis = which_axis_am_i_attached_to(data, num);
			
			//
			// bars are grouped, so 3 bar sets on one chart
			// will arrange them selves next to each other
			// at each value of X, this.group tell the bar
			// where it is in that grouping
			//
			this.group = group;
			
			this.values = this.style.values;

			this.add_values();
		}
		
		//
		// return an array of key info objects:
		//
		public override function get_keys(): Object {
			
			var tmp:Array = [];
			
			for each( var o:Object in this.style.keys ) {
				if ( o.text && o['font-size'] && o.colour ) {
					o.colour = string.Utils.get_colour( o.colour );
					tmp.push( o );
				}
			}
			
			return tmp;
		}
		
		//
		// value is an array (a stack) of bar stacks
		//
		protected override function get_element( index:Number, value:Object ): Element {
			
			//
			// this is the style for a stack:
			//
			var default_style:Object = {
				tip:		this.style.tip,
				values:		value,
				colours:	this.style.colours
			};
			
			
			return new StackCollection( index, default_style, this.group );
		}
		
		
		//
		// get all the Elements at this X position
		//
		protected override function get_all_at_this_x_pos( x:Number ):Array {
			
			var tmp:Array = new Array();
			var p:flash.geom.Point;
			var e:StackCollection;
			
			for ( var i:Number = 0; i < this.numChildren; i++ ) {
			
				// some of the children will will mask
				// Sprites, so filter those out:
				//
				if( this.getChildAt(i) is Element ) {
		
					e = this.getChildAt(i) as StackCollection;
				
					p = e.get_mid_point();
					if ( p.x == x ) {
						var children:Array = e.get_children();
						for each( var child:Element in children )
							tmp.push( child );
					}
				}
			}
			
			return tmp;
		}
	}
}