class NumberFormat
{
	public static var DEFAULT_NUM_DECIMALS:Number = 2;
	
	public var numDecimals:Number = DEFAULT_NUM_DECIMALS;
	public var isFixedNumDecimalsForced:Boolean = false;
	public var isDecimalSeparatorComma:Boolean = false; 
	public var isThousandSeparatorDisabled:Boolean = false; 
	
	
	private function NumberFormat( numDecimals:Number, isFixedNumDecimalsForced:Boolean, isDecimalSeparatorComma:Boolean, isThousandSeparatorDisabled:Boolean )
	{
		this.numDecimals = Parser.getNumberValue (numDecimals, DEFAULT_NUM_DECIMALS, true, false);
		this.isFixedNumDecimalsForced = Parser.getBooleanValue(isFixedNumDecimalsForced,false);
		this.isDecimalSeparatorComma = Parser.getBooleanValue(isDecimalSeparatorComma,false);
		this.isThousandSeparatorDisabled = Parser.getBooleanValue(isThousandSeparatorDisabled,false);
	}
	
	
	
	
	//singleton 
//	public static function getInstance (lv,c:Number):NumberFormat{
//		if (c==2){
//			return NumberFormat.getInstanceY2(lv);
//		} else {
//			return NumberFormat.getInstance(lv);
//		}
//	}

	private static var _instance:NumberFormat = null;
	
	public static function getInstance (lv:LoadVars):NumberFormat{
		if (_instance == null) {
			if (lv==undefined ||  lv == null){
				lv=_root.lv;
			}
			
			_instance = new NumberFormat ( 
				lv.num_decimals,
				lv.is_fixed_num_decimals_forced,
				lv.is_decimal_separator_comma,
				lv.is_thousand_separator_disabled
			 );
//			 trace ("getInstance NEW!!!!");
//			 trace (_instance.numDecimals);
//			 trace (_instance.isFixedNumDecimalsForced);
//			 trace (_instance.isDecimalSeparatorComma);
//			 trace (_instance.isThousandSeparatorDisabled);
		} else {
			 //trace ("getInstance found");
		}
		return _instance;
	}

	private static var _instanceY2:NumberFormat = null;
	
	public static function getInstanceY2 (lv:LoadVars):NumberFormat{
		if (_instanceY2 == null) {
			if (lv==undefined ||  lv == null){
				lv=_root.lv;
			}
			
			_instanceY2 = new NumberFormat ( 
				lv.num_decimals_y2,
				lv.is_fixed_num_decimals_forced_y2,
				lv.is_decimal_separator_comma_y2,
				lv.is_thousand_separator_disabled_y2
			 );
			 //trace ("getInstanceY2 NEW!!!!");
		} else {
			 //trace ("getInstanceY2 found");
		}
		return _instanceY2;
	}	
}