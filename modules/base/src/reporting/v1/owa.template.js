OWA.template = function(options) {

    if (options) {
        this.options = options;
    }
}

OWA.template.prototype = {

    /**
     * Template cache
     */
    _tmplCache = {},

    /**
     * Client side template parser that uses &lt;#= #&gt; and &lt;# code #&gt; expressions.
     * and # # code blocks for template expansion.
     *    
     * @param  str string The text of the template to expand</param>    
     * @param  data mixed Any javascript variable that is to be merged.  
     * @return string  
     */
    parseTemplate = function(str, data) {

        var err = "";
        try {
            var func = this._tmplCache[str];
            if (!func) {
                var strFunc = "var p=[],print=function(){p.push.apply(p,arguments);};" +
                              "with(obj){p.push('" +
                              str.replace(/[\r\t\n]/g, " ")
                               .replace(/'(?=[^#]*#>)/g, "\t")
                               .split("'").join("\\'")
                               .split("\t").join("'")
                               .replace(/<#=(.+?)#>/g, "',$1,'")
                               .split("<#").join("');")
                               .split("#>").join("p.push('")
                               + "');}return p.join('');";

                //alert(strFunc);
                func = new Function("obj", strFunc);
                this._tmplCache[str] = func;
            }
            return func(data);
        } catch (e) { err = e.message; }
        return "< # ERROR: " + err.htmlEncode() + " # >";
    }
}