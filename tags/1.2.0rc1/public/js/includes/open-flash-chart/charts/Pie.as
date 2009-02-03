package charts {
	import charts.Elements.PieLabel;
	import flash.external.ExternalInterface;
	import string.Utils;
	import charts.Elements.Element;
	import charts.Elements.PieSliceContainer;
	import global.Global;
	
	import flash.display.Sprite;

	public class Pie extends Base
	{
		
		private var labels:Array;
		private var links:Array;
		private var colours:Array;
		private var gradientFill:String = 'true'; //toggle gradients
		private var border_width:Number = 1;
		private var label_line:Number;
		private var easing:Function;
		public var style:Object;
		public var total_value:Number = 0;
		
		public function Pie( json:Object )
		{
			this.labels = new Array();
			this.links = new Array();
			this.colours = new Array();
			
			this.style = {
				alpha:				0.5,
				'start-angle':		90,
				'label-colour':		0x900000,	// default label colour
				'font-size':		10,
				'gradient-fill':	false,
				stroke:				1,
				colours:			["#900000", "#009000"],	// slices colours
				animate:			1,
				tip:				'#val# of #total#',	// #percent#, #label#
				'no-labels':		false,
				'on-click':			null
			}
			
			object_helper.merge_2( json, this.style );			
			
			for each( var colour:String in this.style.colours )
				this.colours.push( string.Utils.get_colour( colour ) );
			
			this.label_line = 10;

			this.values = json.values;
			this.add_values();
		}
		
		
		//
		// Pie chart make is quite different to a normal make
		//
		public override function add_values():void {
//			this.Elements= new Array();
			
			//
			// Warning: this is our global singleton
			//
			var g:Global = Global.getInstance();
			
			var total:Number = 0;
			var slice_start:Number = this.style['start-angle'];
			var i:Number;
			var val:Object;
			
			for each ( val in this.values ) {
				if( val is Number )
					total += val;
				else
					total += val.value;
			}
			this.total_value = total;
			
			i = 0;
			for each ( val in this.values ) {
				
				var value:Number = val is Number ? val as Number : val.value;
				var slice_angle:Number = value*360/total;
				
				if( slice_angle >= 0 )
				{
					
					var t:String = this.style.tip.replace('#total#', NumberUtils.formatNumber( this.total_value ));
					t = t.replace('#percent#', NumberUtils.formatNumber( value / this.total_value * 100 ) + '%');
				
					this.addChild(
						this.add_slice(
							i,
							slice_start,
							slice_angle,
							val,		// <-- NOTE: val (object) NOT value (a number)
							t,
							this.colours[(i % this.colours.length)]
							)
						);

					// TODO: fix this and remove
					// tmp.make_tooltip( this.key );
				}
				i++;
				slice_start += slice_angle;
			}
		}
		
		private function add_slice( index:Number, start:Number, angle:Number, value:Object, tip:String, colour:String ): PieSliceContainer {
			
			var default_style:Object = {
					colour:				colour,
					tip:				tip,
					alpha:				this.style.alpha,
					start:				start,
					angle:				angle,
					value:				null,
					animate:			this.style.animate,
					'no-labels':		this.style['no-labels'],
					label:				"",
					'label-colour':		this.style['label-colour'],
					'font-size':		this.style['font-size'],
					'gradient-fill':	this.style['gradient-fill'],
					'on-click':			this.style['on-click']
			};
			
			if ( value is Number )
			{
				default_style.value = value;
				default_style.label = value.toString();
			}
			else
				object_helper.merge_2( value, default_style );

				
				
			// our parent colour is a number, but
			// we may have our own colour:
			if( default_style.colour is String )
				default_style.colour = Utils.get_colour( default_style.colour );
				
			if( default_style['label-colour'] is String )
				default_style['label-colour'] = Utils.get_colour( default_style['label-colour'] );
				
				
			return new PieSliceContainer( index, default_style );
		}
		
		public override function inside__( x:Number, y:Number ): Object {
			var shortest:Number = Number.MAX_VALUE;
			var closest:Element = null;
			
			for ( var i:Number = 0; i < this.numChildren; i++ )
			{
				var slice:PieSliceContainer = this.getChildAt(i) as PieSliceContainer;
				if( slice.is_over() )
					closest = slice.get_slice();
			}
			
			if(closest!=null) tr.ace( closest );
			
			return { element:closest, distance_x:0, distance_y:0 };
		}
		
		public override function closest( x:Number, y:Number ): Object {
			// PIE charts don't do closest to mouse tooltips
			return { Element:null, distance_x:0, distance_y:0 };
		}
		
		
		public override function resize( sc:ScreenCoordsBase ): void {

			var radius:Number = ( Math.min( sc.width, sc.height ) / 2.0 );
		
			var pie:PieSliceContainer;
			//
			// loop over the lables and make sure they are on the screen,
			// reduce the radius until they fit
			//
			var i:Number = 0;
			var outside:Boolean;
			do
			{
				outside = false;
				
				for ( i = 0; i < this.numChildren; i++ )
				{
					pie = this.getChildAt(i) as PieSliceContainer;
					if( !pie.is_label_on_screen(sc, radius) )
						outside = true;
				}
				radius -= 1;
			}
			while ( outside && radius > 10 );
			
			for ( i = 0; i < this.numChildren; i++ )
			{
				pie = this.getChildAt(i) as PieSliceContainer;
				pie.pie_resize(sc, radius);
			}
		}
		
		
		public override function toString():String {
			return "Pie with "+ this.numChildren +" children";
		}
	}
}
