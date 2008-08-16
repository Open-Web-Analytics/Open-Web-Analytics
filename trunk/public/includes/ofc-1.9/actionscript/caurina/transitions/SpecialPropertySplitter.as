/**
 * SpecialPropertySplitter
 * A proxy setter for special properties
 *
 * @author		Zeh Fernando
 * @version		1.0.0
 */

class caurina.transitions.SpecialPropertySplitter {

	public var parameters:Array;

	/**
	 * Builds a new splitter special propery object.
	 * 
	 * @param		p_splitFunction		Function	Reference to the function used to split a value 
	 */
	public function SpecialPropertySplitter (p_splitFunction:Function, p_parameters:Array) {
		splitValues = p_splitFunction;
		parameters = p_parameters;
	}

	/**
	 * Empty shell for the function that receives the value (usually just a Number), and splits it in new property names and values
	 * Must return an array containing .name and .value
	 */
	public function splitValues(p_value:Object, p_parameters:Array):Array {
		// This is rewritten
		return [];
	}

	/**
	 * Converts the instance to a string that can be used when trace()ing the object
	 */
	public function toString():String {
		var value:String = "";
		value += "[SpecialPropertySplitter ";
		value += "splitValues:"+splitValues.toString();
		value += ", ";
		value += "parameters:"+parameters.toString();
		value += "]";
		return value;
	}


}
