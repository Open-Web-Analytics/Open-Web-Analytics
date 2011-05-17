$.templates = {};
// wycats' templating plugin
// (c) Yehuda Katz
// You may distribute this code under the same license as jQuery (BSD or GPL)
(function ($) {
  $.compileTemplate = function (template, begin, end) {
    var rebegin = begin.replace(/([\]{}[\\])/g, '\\$1');
    var reend = end.replace(/([\]{}[\\])/g, '\\$1');

    var code = "self = self || {}; with ($.templates.helpers) { with (self) {" +
      "var _result = '';" +
        template
          .replace(/[\t\r\n]/g, ' ')
          .replace(/^(.*)$/, end + '$1' + begin)
          .replace(new RegExp(reend + "(.*?)" + rebegin, "g"), function (text) {
            return text
              .replace(new RegExp("^" + reend + "(.*)" + rebegin + "$"), "$1")
              .replace(/\\/g, "\\\\")
              .replace(/'/g, "\\'")
              .replace(/^(.*)$/, end + "_result += '$1';" + begin);
          })
          .replace(new RegExp(rebegin + "=(.*?)" + reend, "g"), 
            "_result += (function() { if(typeof($1) == 'undefined' || ($1) == null) return ''; else return ($1) })(); ")
          .replace(new RegExp(rebegin + "(.*?)" + reend, "g"), ' $1 ')
          .replace(new RegExp("^" + reend + "(.*)" + rebegin + "$"), '$1') +
      "_result = _result.replace(/^\\s*/, '').replace(/\\s*$/, '');\n" + 
      "if (_rawText) {return _result};\n"+
      "var ret = $(_result).data('template_obj', self);\n" +
      "jQuery(document).trigger('template.created.' + this.templateName, [{ctx: self, el: ret}]);\n" +
      "return ret;" +
    "}}";
    
    return new Function("self", "_rawText", code);
  };

  /* Some supplemental useful snippets that help build the widget system */
  $(function() {
    $("script[type=text/x-jquery-template]").each(function() {
      $.templates[this.title] = $.compileTemplate(this.innerHTML, "<%", "%>");  
      $.templates[this.title].templateName = this.title;
    });
  });
  
  $.fn.fn = function(name, func) {
    return this.each(function() {
      var meths = $(this).data("methods") || $.data(this, "methods", {});
      meths[name] = func;
    });
  };
  
  $.fn.invoke = function(name, rest) {
    meth = $(this).data("methods")[name];
    if(!meth)
      throw new Error("No method by the name of " + name + " exists on this element");
    else
      return meth.apply(this[0], Array.prototype.slice.call(arguments, 1, -1));
  };

  $.templates = {
    helpers: {
      partial: function(name, json) {
        return $.templates[name](json || {}, true);
      }
    }
  }
  
  $.loadTemplates = function() {
    $.templates = $.templates || {};
    $("script[type=text/x-jquery-template]").each(function() {
      $.templates[this.title] = $.compileTemplate(this.innerHTML, "<%", "%>");
    });
  }

})(jQuery);
