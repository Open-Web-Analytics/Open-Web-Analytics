/*
===============================================================================
Chili is the jQuery code highlighter plugin
...............................................................................
                                               Copyright 2007 / Andrea Ercolino
-------------------------------------------------------------------------------
LICENSE: http://www.opensource.org/licenses/mit-license.php
WEBSITE: http://noteslog.com/chili/
===============================================================================
*/

{
	steps: {
		  mlcom  : { exp: /(?:\(\*[\s\S]*?\*\))|(?:{(?!\$)[\s\S]*?})/ }
		, com    : { exp: /\/\/.*/ }
		, string : { exp: /(?:\'[^\'\\\n]*(?:\\.[^\'\\\n]*)*\')/ }
		, number : { exp: /(?:\b[+-]?(?:\d*\.?\d+|\d+\.?\d*)(?:[eE][+-]?\d+)?\b)|(?:\$[a-zA-Z0-9]+\b)/ }
		, direct : { exp: /\{\$[a-zA-Z]+ .+\}/ }
		, keyword: { exp: /\b(?:abs|addr|and|ansichar|ansistring|array|as|asm|begin|boolean|byte|cardinal|case|char|class|comp|const|constructor|currency|destructor|div|do|double|downto|else|end|except|exports|extended|false|file|finalization|finally|for|function|goto|if|implementation|in|inherited|initialization|int64|integer|interface|is|label|library|longint|longword|mod|nil|not|object|of|on|or|packed|pansichar|pansistring|pchar|pcurrency|pdatetime|pextended|pint64|pointer|private|procedure|program|property|protected|pshortstring|pstring|public|published|pvariant|pwidechar|pwidestring|raise|real|real48|record|repeat|set|shl|shortint|shortstring|shr|single|smallint|string|then|threadvar|to|true|try|type|unit|until|uses|val|var|varirnt|while|widechar|widestring|with|word|write|writeln|xor)\b/ }
	}
}
