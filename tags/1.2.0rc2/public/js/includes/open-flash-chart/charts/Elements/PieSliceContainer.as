package charts.Elements {
	import flash.events.Event;
	import caurina.transitions.Tweener;
	import caurina.transitions.Equations;
	
	public class PieSliceContainer extends Element {
		
		private var TO_RADIANS:Number = Math.PI / 180;
		
		private var animating:Boolean;

		//
		// this holds the slice and the text.
		// we want to rotate the slice, but not the text, so
		// this container holds both
		//
		public function PieSliceContainer( index:Number, style:Object )
		{
			this.addChild( new PieSlice( index, style ) );
			var textlabel:String = style.label;
			if( style['no-labels'] )
				textlabel = '';
				
			this.addChild(
				new PieLabel(
					{
						label:			textlabel,
						colour:			style['label-colour'],
						'font-size':	style['font-size'],
						'on-click':		style['on-click'] } ) );
			
			// this.attach_events();
			// this.animating = false;
		}
		
		public function is_over():Boolean {
			var tmp:PieSlice = this.getChildAt(0) as PieSlice;
			return tmp.is_over;
		}
		
		public function get_slice():Element {
			return this.getChildAt(0) as Element;
		}
		
		public function get_label():PieLabel {
			return this.getChildAt(1) as PieLabel;
		}
		
		
		//
		// the axis makes no sense here, let's override with null and write our own.
		//
		public override function resize( sc:ScreenCoordsBase, axis:Number ): void {}
		
		public function is_label_on_screen( sc:ScreenCoordsBase, slice_radius:Number ): Boolean {
			
			var p:PieSlice = this.getChildAt(0) as PieSlice;
			var l:PieLabel = this.getChildAt(1) as PieLabel;
			
			return l.move_label( slice_radius + 10, sc.get_center_x(), sc.get_center_y(), p.angle+(p.slice_angle/2) );
		}
		
		public function pie_resize( sc:ScreenCoordsBase, slice_radius:Number ): void {
			
			// the label is in the correct position -- see is_label_on_screen()
			var p:PieSlice = this.getChildAt(0) as PieSlice;
			p.pie_resize(sc, slice_radius);
		}
		
		public override function get_tooltip():String {
			var p:PieSlice = this.getChildAt(0) as PieSlice;
			return p.get_tooltip();
		}
		
		public override function mouseOver(event:Event):void {
			
			if ( this.animating ) return;
			
			this.animating = true;
			Tweener.removeTweens(this);
			tr.ace('over container');
			var p:PieSlice = this.getChildAt(0) as PieSlice;
			Tweener.addTween(this, {x:p.position_animate_to.x, y:p.position_animate_to.y, time:0.4, transition:"linear"} );
		}
	}
}
