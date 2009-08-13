package charts.series.bars {
	
	import charts.series.Element;
	import flash.display.Sprite;
	import flash.geom.Point;
	import com.serialization.json.JSON;
	import string.Utils;
	import elements.axis.XAxisLabels;
	
	public class StackCollection extends Element {
		
		protected var tip_pos:flash.geom.Point;
		private var vals:Array;
		public var colours:Array;
		protected var group:Number;
		private var total:Number;
		
		public function StackCollection( index:Number, style:Object, group:Number ) {
			
			this.tooltip = style.tip;
			// this is very similar to a normal
			// PointBarBase but without the mouse
			// over and mouse out events
			this.index = index;
			
			var item:Object;
			
			// a stacked bar has n Y values
			// so this is an array of objects
			this.vals = style.values as Array;
			
			this.total = 0;
			for each( item in this.vals ) {
				if( item != null ) {
					if( item is Number )
						this.total += item;
					else
						this.total += item.val;
				}
			}
		
			//
			// parse our HEX colour strings
			//
			this.colours = new Array();
			for each( var colour:String in style.colours )
				this.colours.push( string.Utils.get_colour( colour ) );
				
			this.group = group;
			this.visible = true;
			
			var prop:String;
			
			var n:Number;	// <-- ugh, leaky variables.
			var bottom:Number = 0;
			var top:Number = 0;
			var colr:Number;
			var count:Number = 0;

			for each( item in this.vals )
			{
				// is this a null stacked bar group?
				if( item != null )
				{
					colr = this.colours[(count % this.colours.length)]
					
					var value:Object = {
						top:		0,		// <-- set this later
						bottom:		bottom,
						colour:		colr,		// <-- default colour (may be overriden later)
						total:		this.total,
						tip:		this.tooltip,
						alpha:		style.alpha
					}
				
					//
					// a valid item is one of [ Number, Object, null ]
					//
					if( item is Number ) {
						top += item;
					}
					else
					{
						// MERGE:
						top += item.val;
						if( item.colour )
							value.colour = string.Utils.get_colour(item.colour);
							
						if( item.tip )
							value.tip = item.tip;
					}
					
					value.top = top;
					
					var p:Stack = new Stack( index, value, group );
					this.addChild( p );
					
					bottom = top;
					count++;
				}
			}
		}
		

		public override function resize( sc:ScreenCoordsBase ):void {
			
			for ( var i:Number = 0; i < this.numChildren; i++ )
			{
				var e:Element = this.getChildAt(i) as Element;
				e.resize( sc );
			}
		}
		
		//
		// for tooltip closest - return the middle point
		// of this stack
		//
		public override function get_mid_point():flash.geom.Point {
			
			// get the first bar in the stack
			var e:Element = this.getChildAt(0) as Element;
				
			return e.get_mid_point();
		}
		
		//
		// called by get_all_at_this_x_pos
		//
		public function get_children(): Array {
			
			var tmp:Array = [];
			for ( var i:Number = 0; i < this.numChildren; i++ ) {
				tmp.push( this.getChildAt(i) );
			}
			return tmp;
		}
		
		public override function get_tip_pos():Object {
			//
			// get top item in stack
			//
			var e:Element = this.getChildAt(this.numChildren-1) as Element;
			return e.get_tip_pos();
		}
		
		
		public override function get_tooltip():String {
			//
			// is the mouse over one of the bars in this stack?
			//
			
			// tr.ace( this.numChildren );
			for ( var i:Number = 0; i < this.numChildren; i++ )
			{
				var e:Element = this.getChildAt(i) as Element;
				if ( e.is_tip )
				{
					//tr.ace( 'TIP' );
					return e.get_tooltip();
				}
			}
			//
			// the mouse is *near* our stack, so show the 'total' tooltip
			//
			return this.tooltip;
		}
		
		/**
		 * See Element
		 */
		public override function tooltip_replace_labels( labels:XAxisLabels ):void {
			
			for ( var i:Number = 0; i < this.numChildren; i++ ) {
				var e:Stack = this.getChildAt(i) as Stack;
				e.replace_x_axis_label( labels.get( this.index ) );
			}
		}
	}
}