class YAxisLabels
{
	public var labels:Array;
	private var steps:Number;
	private var right:Boolean;
	
	function YAxisLabels( y_label_style:YLabelStyle, min:Number, max:Number, steps:Number, nr:Number, lv:LoadVars )
	{
		this.steps = steps;
		this.labels = Array();
		this.right = nr==2;
		
		var name:String = '';
		
		if( !this.right )
		{
			// are the Y Labels visible?
			if( !y_label_style.show_labels )
				return;
			
			name = 'y_label_';
		}
		else
		{
			// is the right Y axis enabled?
			if( !lv.show_y2 )
				return;
			
			// are the Y Labels visible?
			if( !y_label_style.show_labels )
				return;
			
			name = 'y_label_2_';
		}
			
		// labels
		var every:Number = (max-min)/this.steps;
		var count:Number = 0;
		for( var i:Number=min; i<=max; i+=every )
		{
			var title:String = _root.format_y_axis_label(i);
			
			var tmp = {
				textfield: this.yAxisLabel( title, name+String(count++), y_label_style, nr ),
				value: i
				};
			this.labels.push( tmp );
		}
	}

	
	
	function yAxisLabel( title:String, name:String, y_label_style:YLabelStyle )
	{
		// does _root already have this textFiled defined?
		// this happens when we do an AJAX reload()
		// these have to be deleted by hand or else flash goes wonky.
		// In an ideal world we would put this code in the object
		// distructor method, but I don't think actionscript has these :-(
		if( _root[name] != undefined )
			_root[name].removeTextField();
		
		//var mc:MovieClip = _root.createEmptyMovieClip( name, _root.getNextHighestDepth() );
		var tf:TextField = _root.createTextField(name, _root.getNextHighestDepth(), 0, 0, 100, 100);
		//tf.border = true;
		tf.text = title;
		var fmt:TextFormat = new TextFormat();
		fmt.color = y_label_style.colour;
		fmt.font = "Verdana";
		fmt.size = y_label_style.size;
		fmt.align = "right";
		tf.setTextFormat(fmt);
		tf.autoSize="right";
		
		return tf;
	}

	// move y axis labels to the correct x pos
	function move( left:Number, box:Box )
	{
		var maxWidth:Number = this.width();
		
		for( var i=0; i<this.labels.length; i++ )
		{
			// right align
			var tf:TextField = this.labels[i].textfield;
			tf._x = left - tf._width + maxWidth;
		}
		
		// now move it to the correct Y, vertical center align
		for( var i=0; i<this.labels.length; i++ )
		{
			var tf:TextField = this.labels[i].textfield;
			tf._y = box.getY( this.labels[i].value, this.right ) - (tf._height/2);
		}
	}


	function width()
	{
		var max:Number = 0;
		for( var i=0; i<this.labels.length; i++ )
		{
			var tf:TextField = this.labels[i].textfield;
			max = Math.max( max, tf._width );
		}
		return max;
	}
}