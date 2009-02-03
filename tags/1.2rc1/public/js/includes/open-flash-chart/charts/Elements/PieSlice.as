package charts.Elements {
	import flash.display.Sprite;
	import flash.geom.Matrix;
	import flash.geom.Point;
	import flash.text.TextField;
	import flash.text.TextFormat;
	import flash.events.Event;
	import flash.events.MouseEvent;
	import caurina.transitions.Tweener;
	import caurina.transitions.Equations;
	import charts.Pie;
	import flash.display.GradientType;
	
	public class PieSlice extends Element {
		
		private var TO_RADIANS:Number = Math.PI / 180;
		private var colour:Number;
		public var slice_angle:Number;
		private var border_width:Number;
		private var slice_alpha:Number;
		public var angle:Number;
		public var is_over:Boolean;
		public var nolabels:Boolean;
		private var animate:Boolean;
		public var value:Number;
		private var gradientFill:Boolean;
		private var label:String;
		
		public var position_original:flash.geom.Point;
		public var position_animate_to:flash.geom.Point;
		
		public function PieSlice( index:Number, style:Object ) {
		
			this.colour = style.colour;
			this.slice_angle = style.angle;
			this.border_width = 1;
			this.angle = style.start;
			this.slice_alpha = style.alpha;
			this.alpha = style.alpha;
			this.animate = style.animate;
			this.nolabels = style['no-labels'];
			this.value = style.value;
			this.gradientFill = style['gradient-fill'];
			this.label = style.label;
			this.index = index;
			
			this.tooltip = this.replace_magic_values( style.tip );
			
			this.attach_events();
			
			if ( style['on-click'] )
				this.set_on_click( style['on-click'] );
		}
		
		public override function mouseOver(event:Event):void {
			
			Tweener.addTween(this, { alpha:1, time:0.6, transition:Equations.easeOutCirc } );
			this.is_over = true;
			this.dispatchEvent( new ShowTipEvent( this.slice_angle ) );
		}

		public override function mouseOut(event:Event):void {
			Tweener.addTween(this, { alpha:slice_alpha, time:0.8, transition:Equations.easeOutElastic } );
			this.is_over = false;
		}
		
		//
		// This is called by the tooltip when it is finished with us,
		// it is only used in modes the pie does not support
		//
		public override function set_tip( b:Boolean ):void {}
		
		//
		// for most objects this is handled in Element,
		// and this tip is displayed just above that object,
		// but for PieSlice we want the tooltip to follow
		// the mouse:
		//
		public override function get_tip_pos():Object {
			var p:flash.geom.Point = this.localToGlobal( new flash.geom.Point(this.mouseX, this.mouseY) );
			return {x:p.x,y:p.y};
		}

		private function replace_magic_values( t:String ): String {
			
			t = t.replace('#label#', this.label );
			t = t.replace('#val#', NumberUtils.formatNumber( this.value ));
			return t;
		}
		
		//
		// the axis makes no sense here, let's override with null and write our own.
		//
		public override function resize( sc:ScreenCoordsBase, axis:Number ): void { }
		public function pie_resize( sc:ScreenCoordsBase, radius:Number): void {
			
			this.x = sc.get_center_x();
			this.y = sc.get_center_y();
			
			//
			// use to animate the mouse over and mouse out events:
			/*
			this.position_original = new flash.geom.Point(this.x, this.y);
			
			var ang:Number = this.angle + (this.slice_angle / 2);
			
			var animationOffset:Number = 10;
			this.position_animate_to = new flash.geom.Point(
				this.x + (animationOffset * Math.cos(ang * TO_RADIANS)),
				this.y + (animationOffset * Math.sin(ang * TO_RADIANS)) );
			//
			//
			*/
			
			var label_line_length:Number = 10;
			
			this.graphics.clear();
			
			//line from center to edge
			this.graphics.lineStyle( this.border_width, this.colour, 1 );
			//this.graphics.lineStyle( 0, 0, 0 );

			//if the user selected the charts to be gradient filled do gradients
			if( this.gradientFill )
			{
				//set gradient fill
				var colors:Array = [this.colour, this.colour];// this.colour];
				var alphas:Array = [1, 0.5];
				var ratios:Array = [100,255];
				var matrix:Matrix = new Matrix();
				matrix.createGradientBox(radius*2, radius*2, 0, -radius, -radius);
				
				//matrix.createGradientBox(this.stage.stageWidth, this.stage.stageHeight, (3 * Math.PI / 2), -150, 10);
				
				this.graphics.beginGradientFill(GradientType.RADIAL, colors, alphas, ratios, matrix);
			}
			else
				this.graphics.beginFill(this.colour, 1);
			
			this.graphics.moveTo(0, 0);
			this.graphics.lineTo(radius, 0);
			
			var angle:Number = 4;
			var a:Number = Math.tan((angle/2)*TO_RADIANS);
			
			var i:Number = 0;
			var endx:Number;
			var endy:Number;
			var ax:Number;
			var ay:Number;
				
			//draw curve segments spaced by angle
			for ( i = 0; i + angle < this.slice_angle; i += angle) {
				endx = radius*Math.cos((i+angle)*TO_RADIANS);
				endy = radius*Math.sin((i+angle)*TO_RADIANS);
				ax = endx+radius*a*Math.cos(((i+angle)-90)*TO_RADIANS);
				ay = endy+radius*a*Math.sin(((i+angle)-90)*TO_RADIANS);
				this.graphics.curveTo(ax, ay, endx, endy);
			}
			
	
			//when aproaching end of slice, refine angle interval
			angle = 0.08;
			a = Math.tan((angle/2)*TO_RADIANS);
			
			for ( ; i+angle < slice_angle; i+=angle) {
				endx = radius*Math.cos((i+angle)*TO_RADIANS);
				endy = radius*Math.sin((i+angle)*TO_RADIANS);
				ax = endx+radius*a*Math.cos(((i+angle)-90)*TO_RADIANS);
				ay = endy+radius*a*Math.sin(((i+angle)-90)*TO_RADIANS);
				this.graphics.curveTo(ax, ay, endx, endy);
			}
	
			//close slice
			this.graphics.endFill();
			this.graphics.lineTo(0, 0);
			
			if (!this.nolabels) this.draw_label_line( radius, label_line_length, this.slice_angle );
			// return;
			
			if( this.animate )
			{
				if ( this.rotation != this.angle )	// <-- have we already rotated this slice?
					Tweener.addTween(this, { rotation:this.angle, time:1.4, transition:Equations.easeOutCirc } );
			}
			else
			{
				this.rotation = this.angle;
			}
		}
		
		// draw the line from the pie slice to the label
		
		private function draw_label_line( rad:Number, tick_size:Number, slice_angle:Number ):void {
			//draw line
			this.graphics.lineStyle( 1, this.colour, 100 );
			//move to center of arc
			this.graphics.moveTo(rad*Math.cos(slice_angle/2*TO_RADIANS), rad*Math.sin(slice_angle/2*TO_RADIANS));

			//final line positions
			var lineEnd_x:Number = (rad+tick_size)*Math.cos(slice_angle/2*TO_RADIANS);
			var lineEnd_y:Number = (rad+tick_size)*Math.sin(slice_angle/2*TO_RADIANS);
			this.graphics.lineTo(lineEnd_x, lineEnd_y);
		}
		
		
		public override function toString():String {
			return "PieSlice: "+ this.get_tooltip();
		}
	}
}
