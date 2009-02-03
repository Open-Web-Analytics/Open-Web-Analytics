package charts {
	import com.serialization.json.JSON;
	
	public class Factory
	{
		private var attach_right:Array;

		public static function MakeChart( json:Object ) : ObjectCollection
		{
			var collection:ObjectCollection = new ObjectCollection();
			
			// multiple bar charts all have the same X values, so
			// they are grouped around each X value, this tells
			// ScreenCoords how to group them:
			var bar_group:Number = 0;
			var name:String = '';
			var c:Number=1;
			
			var elements:Array = json['elements'] as Array;
			
			for( var i:Number = 0; i < elements.length; i++ )
			{
				tr.ace( elements[i]['type'] );
				
				switch( elements[i]['type'] ) {
					case 'bar' :
						collection.add( new Bar( elements[i], bar_group ) );
						bar_group++;
						break;
					
					case 'line':
						collection.add( new Line( elements[i] ) );
						break;
						
					case 'line_dot':
						collection.add( new LineDot( elements[i] ) );
						break;
					
					case 'line_hollow':
						collection.add( new LineHollow( elements[i] ) );
						break;
						
					case 'area_hollow':
						collection.add( new AreaHollow( elements[i] ) );
						break;
						
					case 'area_line':
						collection.add( new AreaLine( elements[i] ) );
						break;
						
					case 'pie':
						collection.add( new Pie( elements[i] ) );
						break;
						
					case 'hbar':
						collection.add( new HBar( elements[i] ) );
						bar_group++;
						break;
						
					case 'bar_stack':
						collection.add( new BarStack( elements[i], c, bar_group ) );
						bar_group++;
						break;
						
					case 'scatter':
						collection.add( new Scatter( elements[i] ) );
						break;
						
					case 'scatter_line':
						collection.add( new ScatterLine( elements[i] ) );
						break;
						
					case 'bar_sketch':
						collection.add( new BarSketch( elements[i], bar_group ) );
						bar_group++;
						break;
						
					case 'bar_glass':
						collection.add( new BarGlass( elements[i], bar_group ) );
						bar_group++;
						break;
					
					case 'bar_fade':
						collection.add( new BarFade( elements[i], bar_group ) );
						bar_group++;
						break;
					
					case 'bar_3d':
						collection.add( new Bar3D( elements[i], bar_group ) );
						bar_group++;
						break;
					
					case 'bar_filled':
						collection.add( new BarOutline( elements[i], bar_group ) );
						bar_group++;
						break;
						
					case 'shape':
						collection.add( new Shape( elements[i] ) );
						break;
						
					case 'candle':
						collection.add( new Candle( elements[i], bar_group ) );
						bar_group++;
						break;
		
				}
			}
			/*
					
			
				else if ( lv['candle' + name] != undefined )
				{
					ob = new BarCandle(lv, c, bar_group);
					bar_group++;
				}
				
			*/
		
			var y2:Boolean = false;
			var y2lines:Array;
			
			//
			// some data sets are attached to the right
			// Y axis (and min max)
			//
//			this.attach_right = new Array();
				
//			if( lv.show_y2 != undefined )
//				if( lv.show_y2 != 'false' )
//					if( lv.y2_lines != undefined )
//					{
//						this.attach_right = lv.y2_lines.split(",");
//					}
			
			collection.groups = bar_group;
			return collection;
		}
	}
}