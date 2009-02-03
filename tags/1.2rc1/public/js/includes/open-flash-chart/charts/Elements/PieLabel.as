package charts.Elements {
	import flash.text.TextField;
	import flash.text.TextFormat;
	
	public class PieLabel extends TextField {
		public var is_over:Boolean;
		private static var TO_RADIANS:Number = Math.PI / 180;
		
		public function PieLabel( style:Object )
		{
			
			this.text = style.label;
			// legend_tf._rotation = 3.6*value.bar_bottom;
			
			var fmt:TextFormat = new TextFormat();
			fmt.color = style.colour;
			fmt.font = "Verdana";
			fmt.size = style['font-size'];
			fmt.align = "center";
			this.setTextFormat(fmt);
			this.autoSize = "left";
		}
		
		public function move_label( rad:Number, x:Number, y:Number, ang:Number ):Boolean {
			
			//text field position
			var legend_x:Number = x+rad*Math.cos((ang)*TO_RADIANS);
			var legend_y:Number = y+rad*Math.sin((ang)*TO_RADIANS);
			
			//if legend stands to the right side of the pie
			if(legend_x<x)
				legend_x -= this.width;
					
			//if legend stands on upper half of the pie
			if(legend_y<y)
				legend_y -= this.height;
			
			this.x = legend_x;
			this.y = legend_y;
			
			// does the label fit onto the stage?
			if( (this.x > 0) &&
			    (this.y > 0) &&
				(this.y + this.height < this.stage.stageHeight ) &&
				(this.x+this.width<this.stage.stageWidth) )
				return true;
			else
				return false;
		}
		
		public function resize( sc:ScreenCoords ): void {
			
		}
	}
}