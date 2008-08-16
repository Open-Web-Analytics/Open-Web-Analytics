/**
 * SpecialProperty
 * A kind of a getter/setter for special properties
 *
 * @author		Zeh Fernando
 * @version		1.0.1
 */

class caurina.transitions.SpecialProperty {

	private var parameters:Array;

	/**
	 * Builds a new special property object.
	 * 
	 * @param		p_getFunction		Function	Reference to the function used to get the special property value
	 * @param		p_setFunction		Function	Reference to the function used to set the special property value
	 * @param		p_parameters		Array		Additional parameters that should be passed to the function when executing (so the same function can apply to different special properties)
	 */
	public function SpecialProperty (p_getFunction:Function, p_setFunction:Function, p_parameters:Array) {
		getValue = p_getFunction;
		setValue = p_setFunction;
		parameters = p_parameters;
	}

	/**
	 * Empty shell for the function that gets the value.
	 */
	public function getValue(p_obj:Object, p_parameters:Array):Number {
		// This is rewritten
		return null;
	}
		
	/**
	 * Empty shell for the function that sets the value.
	 */
	public function setValue(p_obj:Object, p_value:Number, p_parameters:Array):Void {
		// This is rewritten
	}

	/**
	 * Converts the instance to a string that can be used when trace()ing the object
	 */
	public function toString():String {
		var value:String = "";
		value += "[SpecialProperty ";
		value += "getValue:"+getValue.toString();
		value += ", ";
		value += "setValue:"+setValue.toString();
		value += ", ";
		value += "parameters:"+parameters.toString();
		value += "]";
		return value;
	}


}
