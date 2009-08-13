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
	steps:
	{
		  mlcom   : { exp: /\/\*[^*]*\*+(?:[^\/][^*]*\*+)*\// }
		, com     : { exp: /\/\/.*/ }
		, regexp  : { exp: /\/[^\/\\\n]*(?:\\.[^\/\\\n]*)*\/[gim]*/ }
		, string  : { exp: /(?:\'[^\'\\\n]*(?:\\.[^\'\\\n]*)*\')|(?:\"[^\"\\\n]*(?:\\.[^\"\\\n]*)*\")/ }
		, numbers : { exp: /\b[+-]?(?:\d*\.?\d+|\d+\.?\d*)(?:[eE][+-]?\d+)?\b/ }
		, keywords: { exp: /\b(arguments|break|case|catch|continue|default|delete|do|else|false|for|function|if|in|instanceof|new|null|return|switch|this|true|try|typeof|var|void|while|with)\b/ }
		, global  : { exp: /\b(toString|valueOf|window|element|prototype|constructor|document|escape|unescape|parseInt|parseFloat|setTimeout|clearTimeout|setInterval|clearInterval|NaN|isNaN|Infinity)\b/ }

		, "jquery utilities"  : { 
			  exp        : /(?:\$\.browser|\$\.each|\$\.extend|\$\.grep|\$\.map|\$\.merge|\$\.trim)\b/
			, replacement: '<span class="jquery" title="$0"><span class="global">$$</span></span>'
		}
		,"jquery private"     : {
			  exp        : /(?:\$\.find|\$\.parents|\$\.sibling|\.domManip|\.eventTesting|\.extend|\.get|\.init|\.jquery|\.pushStack)\b/
			, replacement: '<span class="jquery" title="$0"><span class="private">$$</span></span>'
		}
		,"jquery ajax"        : {
			  exp        : /(?:\$\.ajax|\$\.ajaxSetup|\$\.ajaxTimeout|\$\.get|\$\.getIfModified|\$\.getJSON|\$\.getScript|\$\.post|.ajaxComplete|.ajaxError|.ajaxSend|.ajaxStart|.ajaxStop|.ajaxSuccess|.load|.loadIfModified|.serialize)\b/
			, replacement: '<span class="jquery" title="$0"><span class="ajax">$$</span></span>'
		}
		, "jquery object"     : { 
			  exp        : /jQuery|\$(?=\W)/
			, replacement: '<span class="jquery" title="$0"><span class="object">$$</span></span>'
		}
		,"jquery core"        : {
			  exp        : /\$\.extend|\$\.noConflict|\.(?:each|eq|get|gt|index|lt|size)\b/
			, replacement: '<span class="jquery" title="$0"><span class="core">$$</span></span>'
		}
		,"jquery css"         : {
			  exp        : /\.(?:css|height|width)\b/
			, replacement: '<span class="jquery" title="$0"><span class="css">$$</span></span>'
		}
		,"jquery attributes"  : {
			  exp        : /\.(?:addClass|attr|html|removeAttr|removeClass|text|toggleClass|val)\b/
			, replacement: '<span class="jquery" title="$0"><span class="attributes">$$</span></span>'
		}
		,"jquery traversing"  : {
			  exp        : /\.(?:add|children|contains|end|filter|find|is|next|not|parent|parents|prev|siblings)\b/
			, replacement: '<span class="jquery" title="$0"><span class="traversing">$$</span></span>'
		}
		,"jquery manipulation": {
			  exp        : /\.(?:after|append|appendTo|before|clone|empty|insertAfter|insertBefore|prepend|prependTo|remove|wrap)\b/
			, replacement: '<span class="jquery" title="$0"><span class="manipulation">$$</span></span>'
		}
		,"jquery effects"     : {
			  exp        : /\.(?:animate|fadeIn|fadeOut|fadeTo|hide|show|slideDown|slideToggle|slideUp|toggle)\b/
			, replacement: '<span class="jquery" title="$0"><span class="effects">$$</span></span>'
		}
		,"jquery events"      : {
			  exp        : /\.(?:bind|blur|change|click|dblclick|error|focus|hover|keydown|keypress|keyup|load|mousedown|mousemove|mouseout|mouseover|mouseup|one|ready|resize|scroll|select|submit|toggle|trigger|unbind|unload)\b/
			, replacement: '<span class="jquery" title="$0"><span class="events">$$</span></span>'
		}
	}
}