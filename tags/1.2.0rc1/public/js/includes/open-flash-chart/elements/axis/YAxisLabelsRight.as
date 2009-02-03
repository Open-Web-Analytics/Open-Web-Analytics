package elements.axis {
	import flash.text.TextField;
	
	public class YAxisLabelsRight extends YAxisLabelsBase {
		
		public function YAxisLabelsRight( parent:YAxisRight, json:Object ) {
			
			var values:Array;
			var ok:Boolean = false;
			
			if( json.y_axis_right )
			{
				if( json.y_axis_right.labels )
				{
					values = [];
					var i:Number = 0;
					for each( var s:String in json.y_axis_right.labels )
					{
						values.push( { val:s, pos:i } );
						i++;
					}
					
					//
					// alter the MinMax object:
					//
					parent.set_y_max( values.length - 1 );
					ok = true;
				}
			}
			
			if( !ok && parent.style.visible )
				values = make_labels( parent.style.min, parent.style.max, true, 1 );
			
			super( values, 1, json, 'y_label_2_', 'y2');
		}

		// move y axis labels to the correct x pos
		public override function resize( left:Number, box:ScreenCoords ):void {
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
				tf.y = box.get_y_from_val( tf.y_val, true ) - (tf.height / 2);
				if (tf.y < 0 && box.top == 0) // Tried setting tf.height but that didnt work 
					tf.y = this.rotate == "vertical" ? tf.height : tf.textHeight - tf.height;
			}
		}
	}
}