package elements.axis {
	import flash.text.TextField;
	
	public class YAxisLabelsLeft extends YAxisLabelsBase {

		public function YAxisLabelsLeft( parent:YAxisLeft, json:Object ) {
			
			var values:Array;
			var ok:Boolean = false;
			
			if( json.y_axis )
			{
				if( json.y_axis.labels )
				{
					values = [];
					
					// use passed in min if provided else zero
					var i:Number = (json.y_axis && json.y_axis.min) ? json.y_axis.min : 0;
					for each( var s:String in json.y_axis.labels )
					{
						values.push( { val:s, pos:i } );
						i++;
					}
					
					//
					// alter the MinMax object:
					//
					// use passed in max if provided else the number of values less 1
					i = (json.y_axis && json.y_axis.max) ? json.y_axis.max : values.length - 1;
					parent.set_y_max( i );
					ok = true;
				}
			}
			
			if( !ok )
			{
				values = this.make_labels( parent.style.min, parent.style.max, false, parent.style.steps );
			}
			
			
			super(values,1,json,'y_label_','y');
		}

		// move y axis labels to the correct x pos
		public override function resize( left:Number, sc:ScreenCoords ):void {
			var maxWidth:Number = this.get_width();
			var i:Number;
			var tf:YTextField;
			
			for( i=0; i<this.numChildren; i++ ) {
				// right align
				tf = this.getChildAt(i) as YTextField;
				tf.x = left - tf.width + maxWidth;
			}
			
			// now move it to the correct Y, vertical center align
			for ( i=0; i < this.numChildren; i++ ) {
				tf = this.getChildAt(i) as YTextField;
				
				// tr.ace( '***' );
				// tr.ace( tf.y_val );
				// tr.ace( sc.get_y_from_val( tf.y_val, false ) );
				
				tf.y = sc.get_y_from_val( tf.y_val, false ) - (tf.height / 2);
				if (tf.y < 0 && sc.top == 0) // Tried setting tf.height but that didnt work 
					tf.y = this.rotate == "vertical" ? tf.height : tf.textHeight - tf.height;
			}
		}
	}
}