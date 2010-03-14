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
	  ignoreCase: true
	, steps: {
		  mlcom    : { exp : /((?:^|\n)%REM\b.*)((?:.|\n)*?)(\n%END\s*REM\b.*)/
			,replacement: '<span class="directive">$1</span><span class="mlcom">$2</span><span class="directive">$3</span>'
		}
		, com      : { exp : /(?:\'.*)|(?:\bREM\b.*)/ }
		, mlstr    : { exp : /(?:\{[^}]*\})|(?:\|[^|]*\|)/ }
		, str      : { exp : /(?:\"[^\"].*?\")|\"\"/ }
		, keyd     : { exp : /\b(?:Bin|Chr|Command|Curdir|Curdrive|Date|Dir|Environ|Error|Format|Hex|Implode|Input|Inputb|Inputbox|Inputbp|Lcase|Left|Leftb|Leftbp|Leftc|Ltrim|Mid|Midb|Midbp|Midc|Oct|Right|Rightb|Rightbp|Rightc|Rtrim|Space|Str|String|Strleft|Strleftback|Strright|Strrightback|Strtoken|Time|Trim|Ucase|Uchr|Ustring)[$](?:\s)/ }
		, keyw     : { exp : /\b(?:Abs|Access|Acos|Activateapp|Alias|And|Any|Appactivate|Append|Arrayappend|Arraygetindex|Arrayreplace|Arrayunique|As|Asc|Asin|Atn|Atn2|Base|Beep|Bin|Binary|Bind|Boolean|Byte|Byval|Call|Case|Cbool|Cbyte|Ccur|Cdat|Cdbl|Chdir|Chdrive|Chr|Cint|Class|Clng|Close|Codelock|Codelockcheck|Codeunlock|Command|Compare|Const|Cos|Createlock|Csng|Cstr|Curdir|Curdrive|Currency|Cvar|Cvdate|Datatype|Date|Datenumber|Dateserial|Datevalue|Day|Declare|Defbool|Defbyte|Defcur|Defdbl|Defint|Deflng|Defsng|Defstr|Defvar|Delete|Destroylock|Dim|Dir|Do|Doevents|Double|Else|Elseif|End|Environ|Eof|Eqv|Erase|Erl|Err|Error|Evaluate|Event|Execute|Exit|Exp|Explicit|False|Fileattr|Filecopy|Filedatetime|Filelen|Fix|For|Forall|Format|Fraction|Freefile|From|Fulltrim|Function|Get|Getattr|Getfileattr|Getthreadinfo|Gosub|Goto|Hex|Hour|If|Imesetmode|Imestatus|Imp|Implode|In|Input|Inputb|Inputbox|Inputbp|Instr|Instrb|Instrbp|Instrc|Int|Integer|Is|Isa|Isarray|Isdate|Iselement|Isempty|Islist|Isnull|Isnumeric|Isobject|Isscalar|Isunknown|Join|Kill|Lbound|Lcase|Left|Leftb|Leftbp|Leftc|Len|Lenb|Lenbp|Lenc|Let|Lib|Like|Line|List|Listtag|Lmbcs|Loc|Lock|Lof|Log|Long|Loop|Lset|Lsi_info|Lsserver|Ltrim|Me|Messagebox|Mid|Midb|Midbp|Midc|Minute|Mkdir|Mod|Month|Msgbox|Name|New|Next|Nocase|Nopitch|Not|Nothing|Now|Null|Oct|On|Open|Option|Or|Output|Pi|Pitch|Preserve|Print|Private|Property|Public|Published|Put|Random|Randomize|Read|Redim|Remove|Replace|Reset|Resume|Return|Right|Rightb|Rightbp|Rightc|Rmdir|Rnd|Round|Rset|Rtrim|Second|Seek|Select|Sendkeys|Set|Setattr|Setfileattr|Sgn|Shared|Shell|Sin|Single|Sleep|Space|Spc|Split|Sqr|Static|Step|Stop|Str|Strcomp|Strcompare|Strconv|String|Strleft|Strleftback|Strright|Strrightback|Strtoken|Sub|Tab|Tan|Text|Then|Time|Timenumber|Timer|Timeserial|Timevalue|To|Today|Trim|True|Type|Typename|Ubound|Ucase|Uchr|Uni|Unicode|Unlock|Until|Use|Uselsx|Ustring|Val|Variant|Vartype|Weekday|Wend|While|Width|With|Write|Xor|Year|Yield)\b/ }
		, directive: { exp : /((?:^|\n)(?:%else|%elseif|%end|%if)\b)|(?:(?:^|\n)%include\b.*)/ }
		, notes    : { exp : /\b(?:NotesACL|NotesACLEntry|NotesAdministrationProcess|NotesAgent|NotesColorObject|NotesDatabase|NotesDateRange|NotesDateTime|NotesDbDirectory|NotesDocument|NotesDocumentCollection|NotesDOMAttributeNode|NotesDOMCDATASectionNode|NotesDOMCharacterDataNode|NotesDOMCommentNode|NotesDOMDocumentFragmentNode|NotesDOMDocumentNode|NotesDOMDocumentTypeNode|NotesDOMElementNode|NotesDOMEntityNode|NotesDOMEntityReferenceNode|NotesDOMNamedNodeMap|NotesDOMNode|NotesDOMNodeList|NotesDOMNotationNode|NotesDOMParser|NotesDOMProcessingInstructionNode|NotesDOMTextNode|NotesDOMXMLDeclNode|NotesDXLExporter|NotesDXLImporter|NotesEmbeddedObject|NotesForm|NotesInternational|NotesItem|NotesLog|NotesMIMEEntity|NotesMIMEHeader|NotesName|NotesNewsLetter|NotesNoteCollection|NotesOutline|NotesOutlineEntry|NotesRegistration|NotesReplication|NotesReplicationEntry|NotesRichTextDocLink|NotesRichTextItem|NotesRichTextNavigator|NotesRichTextParagraphStyle|NotesRichTextRange|NotesRichTextSection|NotesRichTextStyle|NotesRichTextTab|NotesRichTextTable|NotesSAXAttributeList|NotesSAXException|NotesSAXParser|NotesSession|NotesStream|NotesTimer|NotesView|NotesViewColumn|NotesViewEntry|NotesViewEntryCollection|NotesViewNavigator|NotesXMLProcessor|NotesXSLTransformer)\b/ }
		, notesui  : { exp : /\b(?:Button|Field|Navigator|NotesUIDatabase|NotesUIDocument|NotesUIScheduler|NotesUIView|NotesUIWorkspace)\b/ }
	}
}
