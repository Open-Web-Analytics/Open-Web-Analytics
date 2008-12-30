/* */

package elements.axis {
	
	import flash.display.Sprite;
    import flash.text.TextField;
	import flash.geom.Rectangle;
	
	public class AxisLabel extends TextField {
		public var xAdj:Number = 0;
		public var yAdj:Number = 0;
		public var leftOverhang:Number = 0;
		public var rightOverhang:Number = 0;
		public var xVal:Number = NaN;
		public var yVal:Number = NaN;
		
		public function AxisLabel()	{}
		
		/**
		 * Rotate the label and align it to the X Axis tick
		 * 
		 * @param	rotation
		 */

		public function rotate_and_align( rotation:Number, parent:Sprite ): void
		{
			// NOTE: We want the labels to be top justified along the X Axis
			// NOTE: Child rotation is between -180 and 180

			this.rotation = rotation;
			
			var titleRect:Rectangle = this.getBounds(parent);
			var tempRect:Rectangle;

			if (this.rotation == 0)
			{
				this.xAdj = - titleRect.width / 2;
				///yAdj += 0;
			} 
			else if (this.rotation == -90) 
			{
				this.xAdj = - titleRect.width / 2;
				this.yAdj = titleRect.height;
			} 
			else if (this.rotation == 90) 
			{
				this.xAdj = titleRect.width / 2;
				//yAdj += 0;
			} 
			else if (Math.abs(this.rotation) == 180) 
			{
				this.xAdj = titleRect.width / 2;
				this.yAdj = titleRect.height;
			} 
			else if (this.rotation < -90) //-90
			{
				// temporarily change rotation to easily determine x adjustment
				this.rotation += 180;
				tempRect = this.getBounds(parent);
				this.rotation -= 180;

				this.xAdj = titleRect.width + ((3 * tempRect.x) / 2);
				this.yAdj = -titleRect.y;
			} 
			else if (this.rotation < 0) 
			{
				// temporarily change rotation to easily determine x adjustment
				this.rotation += 90;
				tempRect = this.getBounds(parent);
				this.rotation -= 90;

				this.xAdj = -titleRect.width - (tempRect.x / 2);
				this.yAdj = -titleRect.y;
			} 
			else if (this.rotation < 90) 
			{
				this.xAdj = -titleRect.x / 2;
				this.yAdj = -titleRect.y;
			} 
			else 
			{
				// temporarily change rotation to easily determine x adjustment
				this.rotation -= 90;
				tempRect = this.getBounds(parent);
				this.rotation += 90;

				this.xAdj = -tempRect.x / 2;
				this.yAdj = -titleRect.y;
			}
			
			this.leftOverhang = Math.abs(titleRect.x + this.xAdj);
			this.rightOverhang = Math.abs(titleRect.x + titleRect.width + this.xAdj);
		}
	}
}