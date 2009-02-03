package elements.axis {
	import string.Utils;
	
	public class YLabelStyle
	{
		public var size:Number;
		public var colour:Number = 0x000000;
		
		public var show_labels:Boolean;
		public var show_y2:Boolean;

		public function YLabelStyle( json:Object, name:String )
		{
			this.size = 10;
			this.colour = 0x000000;
			this.show_labels = true;
			var comma:Number;
			var none:Number;
			var tmp:Array;
			
			if( json[name+'_label_style'] == undefined )
				return;
					
			// is it CSV?
			comma = json[name+'_label_style'].lastIndexOf(',');
				
			if( comma<0 )
			{
				none = json[name+'_label_style'].lastIndexOf('none',0);
				if( none>-1 )
				{
					this.show_labels = false;
				}
			}
			else
			{
				tmp = json[name+'_label_style'].split(',');
				if( tmp.length > 0 )
					this.size = tmp[0];
					
				if( tmp.length > 1 )
					this.colour = Utils.get_colour(tmp[1]);
			}
		}
	}
}