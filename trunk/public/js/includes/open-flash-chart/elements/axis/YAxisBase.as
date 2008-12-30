package elements.axis {
	import flash.display.Sprite;
	import string.Utils;
	
	public class YAxisBase extends Sprite {
		//protected var _width:Number=0;
		//protected var steps:Number;
		
		protected var stroke:Number;
		protected var tick_length:Number;
		protected var colour:Number;
		//
		// offset: see ScreenCoords for a details explination
		//
		public var offset:Object;
		
		protected var grid_colour:Number;
		
		public var style:Object;
		
		protected var labels:YAxisLabelsBase;
		
		function YAxisBase( json:Object, name:String )
		{
	
			//
			// If we set this.style in the parent, then
			// access it here it is null, but if we do
			// this hack then it is OK:
			//
			this.style = this.get_style();
			
			if( json[name] )
				object_helper.merge_2( json[name], this.style );
				
			
			this.colour = Utils.get_colour( style.colour );
			this.grid_colour = Utils.get_colour( style['grid-colour'] );
			this.stroke = style.stroke;
			this.tick_length = style['tick-length'];
			
			// try to avoid infinate loops...
			if ( this.style.steps == 0 )
				this.style.steps = 1;
				
			if ( this.style.steps < 0 )
				this.style.steps *= -1;
			
			this.offset = { 'offset':style.offset, 'value':style.steps };
			
		}
		
		public function get_style():Object { return null;  }
		
		//
		// may be called by the labels
		//
		public function set_y_max( m:Number ):void {
			this.style.max = m;
		}
		
		public function get_range():Range {
			return new Range( this.style.min, this.style.max, this.style.steps );
		}
		
		public function resize( label_pos:Number, sc:ScreenCoords ):void {
		}
		
		public function get_width():Number {
			return this.stroke + this.tick_length + this.labels.width;
		}
		
		public function die(): void {
			
			this.offset = null;
			this.style = null;
			if (this.labels != null) this.labels.die();
			this.labels = null;
			
			this.graphics.clear();
			while ( this.numChildren > 0 )
				this.removeChildAt(0);
		}
		
	}
}