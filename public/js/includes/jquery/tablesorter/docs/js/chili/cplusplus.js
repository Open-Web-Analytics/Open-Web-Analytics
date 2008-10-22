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
		  mlcom   : { exp: /\/\*[^*]*\*+(?:[^\/][^*]*\*+)*\// }
		, com     : { exp: /\/\/.*/ }
		, preproc : { exp: /[\^\n]\s*#\w+/ }
		, string  : { exp: /(?:\'[^\'\\\n]*(?:\\.[^\'\\\n]*)*\')|(?:\"[^\"\\\n]*(?:\\.[^\"\\\n]*)*\")/ }
		, number  : { exp: /\b[+-]?(?:\d*\.?\d+|\d+\.?\d*)(?:[eE][+-]?\d+)?\b/ }
		, datatype: { exp: /\b(?:ATOM|BOOL|BOOLEAN|BYTE|CHAR|COLORREF|DWORD|DWORD32|DWORD64|DWORDLONG|DWORD_PTR|FILE|FLOAT|HACCEL|HALF_PTR|HANDLE|HBITMAP|HBRUSH|HCOLORSPACE|HCONV|HCONVLIST|HCURSOR|HDC|HDDEDATA|HDESK|HDROP|HDWP|HENHMETAFILE|HFILE|HFONT|HGDIOBJ|HGLOBAL|HHOOK|HICON|HINSTANCE|HKEY|HKL|HLOCAL|HMENU|HMETAFILE|HMODULE|HMONITOR|HPALETTE|HPEN|HRESULT|HRGN|HRSRC|HSZ|HWINSTA|HWND|INT|INT32|INT64|INT_PTR|LANGID|LCID|LCTYPE|LGRPID|LONG|LONG32|LONG64|LONGLONG|LONG_PTR|LPARAM|LPBOOL|LPBYTE|LPCOLORREF|LPCSTR|LPCTSTR|LPCVOID|LPCWSTR|LPDWORD|LPHANDLE|LPINT|LPLONG|LPSTR|LPTSTR|LPVOID|LPWORD|LPWSTR|LRESULT|PBOOL|PBOOLEAN|PBYTE|PCHAR|PCSTR|PCTSTR|PCWSTR|PDWORD32|PDWORD64|PDWORDLONG|PDWORD_PTR|PFLOAT|PHALF_PTR|PHANDLE|PHKEY|PINT|PINT32|PINT64|PINT_PTR|PLCID|PLONG|PLONG32|PLONG64|PLONGLONG|PLONG_PTR|POINTER_32|POINTER_64|PSHORT|PSIZE_T|PSSIZE_T|PSTR|PTBYTE|PTCHAR|PTSTR|PUCHAR|PUHALF_PTR|PUINT|PUINT32|PUINT64|PUINT_PTR|PULONG|PULONG32|PULONG64|PULONGLONG|PULONG_PTR|PUSHORT|PVOID|PWCHAR|PWORD|PWSTR|SC_HANDLE|SC_LOCK|SERVICE_STATUS_HANDLE|SHORT|SIZE_T|SSIZE_T|TBYTE|TCHAR|UCHAR|UHALF_PTR|UINT|UINT32|UINT64|UINT_PTR|ULONG|ULONG32|ULONG64|ULONGLONG|ULONG_PTR|USHORT|USN|VOID|WCHAR|WORD|WPARAM|_EXCEPTION_POINTERS|_FPIEEE_RECORD|_HEAPINFO|_HFILE|_PNH|__finddata64_t|__int16|__int32|__int64|__int8|__stat64|__time64_t|__timeb64|__wchar_t|__wfinddata64_t|_complex|_dev_t|_diskfree_t|_exception|_finddata_t|_finddatai64_t|_off_t|_onexit_t|_purecall_handler|_stat|_stati64|_timeb|_utimbuf|_wfinddata_t|_wfinddatai64_t|bool|char|clock_t|div_t|double|float|fpos_t|int|intptr_t|jmp_buf|lconv|ldiv_t|long|mbstate_t|ptrdiff_t|short|sig_atomic_t|signed|size_t|terminate_function|time_t|tm|uintptr_t|va_list|wchar_t|wctrans_t|wctype_t|wint_t)\b/ }
		, keyword : { exp: /\b(?:__declspec|__exception|__finally|__try|break|case|catch|class|const|const_cast|continue|default|delete|deprecated|dllexport|dllimport|do|dynamic_cast|else|enum|explicit|extern|false|for|friend|goto|if|inline|mutable|naked|namespace|new|noinline|noreturn|nothrow|private|protected|public|register|reinterpret_cast|return|selectany|sizeof|static|static_cast|struct|switch|template|this|thread|throw|true|try|typedef|typeid|typename|union|using|uuid|virtual|void|volatile|whcar_t|while)\b/ }
	}
}
