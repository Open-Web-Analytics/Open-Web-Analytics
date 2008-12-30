package {
	
	public class Range
	{
		public var min:Number;
		public var max:Number;
		public var step:Number;
		
		public function Range( min:Number, max:Number, step:Number )
		{
			this.min = min;
			this.max = max;
			this.step = step;
		}
		
		public function count():Number {
			return this.max - this.min;
		}
		
		public function toString():String {
			return 'Range : ' + this.min +', ' + this.max;
		}
	}
}