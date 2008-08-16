class XLabelStyle
{
	public var size:Number = 10;
	public var colour:Number = 0x000000;
	public var vertical:Boolean = false;
	public var diag:Boolean = false;
	public var step:Number = 1;
	public var show_labels:Boolean;

	public function XLabelStyle( lv:LoadVars )
	{
		if( lv.x_label_style == undefined )
			return;
			
		// is it CSV?
		var comma:Number = lv.x_label_style.lastIndexOf(',');
		
		if( comma<0 )
		{
			var none:Number = lv.x_label_style.lastIndexOf('none',0);
			if( none>-1 )
			{
				this.show_labels = false;
			}
		}
		else
		{
			this.show_labels = true;
			
			var tmp:Array = lv.x_label_style.split(',');
			if( tmp.length > 0 )
				this.size = tmp[0];
				
			if( tmp.length > 1 )
				this.colour = _root.get_colour(tmp[1]);
				
			if( tmp.length > 2 )
			{
				this.vertical = (Number(tmp[2])==1);
				this.diag = (Number(tmp[2])==2);
			}
			
			if( tmp.length > 3 )
				if( Number(tmp[3]) > 0 )
					this.step = Number(tmp[3]);
		}
	}
}