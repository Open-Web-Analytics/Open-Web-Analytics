package charts.Elements {
	import flash.display.Sprite;
	
	public class PointBarOutline extends PointBarBase {
		private var outline:Number;
		
		public function PointBarOutline( index:Number, style:Object, group:Number )	{
			
			super( index, style, style.colour, style.tip, group );
			this.outline = style['outline-colour'];
		}
		
		public override function resize( sc:ScreenCoordsBase, axis:Number ):void {
			
			var h:Object = this.resize_helper( sc as ScreenCoords, axis );
			
			this.graphics.clear();
			this.graphics.lineStyle(1, this.outline, 1);
			this.graphics.beginFill( this.colour, 1.0 );
			this.graphics.moveTo( 0, 0 );
			this.graphics.lineTo( h.width, 0 );
			this.graphics.lineTo( h.width, h.height );
			this.graphics.lineTo( 0, h.height );
			this.graphics.lineTo( 0, 0 );
			this.graphics.endFill();
			
		}
	}
}