// Tempest jQuery Templating Plugin
// ================================
//
// Copyright (c) 2009 Nick Fitzgerald - http://fitzgeraldnick.com/
//
// Permission is hereby granted, free of charge, to any person obtaining a copy
// of this software and associated documentation files (the "Software"), to deal
// in the Software without restriction, including without limitation the rights
// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
// copies of the Software, and to permit persons to whom the Software is
// furnished to do so, subject to the following conditions:
//
// The above copyright notice and this permission notice shall be included in
// all copies or substantial portions of the Software.
//
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
// THE SOFTWARE.

// JSLint
"use strict";

(function ($) {
    // PRIVATE VARIABLES
    var templateCache = {},

        // TAG REGULAR EXPRESSIONS
        // Overwrite these if you want, but don't blame me when stuff goes wrong.
        OPEN_VAR_TAG = /\{\{[\s]*?/g,
        CLOSE_VAR_TAG = /[\s]*?\}\}/g,
        OPEN_BLOCK_TAG = /\{%[\s]*?/g,
        CLOSE_BLOCK_TAG = /[\s]*?%\}/g,

        // Probably, you don't want to mess with these, as they are built from
        // the ones above.
        VAR_TAG = new RegExp(OPEN_VAR_TAG.source +
                             "[\\w\\-\\.]+?" +
                             CLOSE_VAR_TAG.source, "g"),

        BLOCK_TAG = new RegExp(OPEN_BLOCK_TAG.source +
                               "[\\w]+?(?:[ ]+?[\\w\\-\\.]*?)*?" +
                               CLOSE_BLOCK_TAG.source, "g"),

        END_BLOCK_TAG = new RegExp(OPEN_BLOCK_TAG.source +
                                   "end[\\w]*?" +
                                   CLOSE_BLOCK_TAG.source, "g"),

        // All block tags stored in here. Tags have a couple things to work
        // with:
        //
        // * "args" property is set before render:
        //     - Example: {% tag_type arg1 arg2 foo bar %}
        //         * The "args" property would be set to
        //               ["arg1", "arg2", "foo", "bar"]
        //           in this example. The tag's render method could look them
        //           up in the context object, or could do whatever it wanted
        //           to do with it.
        // * "subNodes" property which is an array of all the nodes between
        //   the block tag and it's corresponding {% end... %} tag
        //     - NOTE: This property is only set for a block if it has the
        //       "expectsEndTag" property set to true.
        // * Every block tag should have a "render" method that takes one
        //   argument: a context object. It should return a string.
        BLOCK_NODES = {
            "for": {
                expectsEndTag: true,
                render: function (context) {
                    var args = this.args,
                    subNodes = this.subNodes,
                    renderedNodes = [],
                    i, itemName, arrName, arr, forContext, tmpObj;

                    if (args.length === 3 && args[1] === "in") {
                        itemName = args[0];
                        arrName = args[2];
                        arr = getValFromObj(arrName, context);

                        for (i = 0; i < arr.length; i++) {
                            tmpObj = {};
                            tmpObj[itemName] = arr[i];
                            tmpObj._index = i;
                            forContext = $.extend(true, {}, context, tmpObj);

                            $.each(subNodes, function (j, node) {
                                renderedNodes.push(
                                    node.render(forContext)
                                );
                            });
                        }

                        return renderedNodes.join("");
                    }
                    else {
                        throw new TemplateSyntaxError(
                            "Bad for tag syntax. Use {% for <item> in <array> %}"
                        );
                    }
                }
            },
            "if": {
                expectsEndTag: true,
                render: function (context) {
                    var rendered_nodes = [],
                        subNodes = this.subNodes;

                    // Check the truthiness of the argument.
                    if (!!context[this.args[0]]) {
                        $.each(subNodes, function (i, node) {
                            rendered_nodes.push(node.render(context));
                        });
                    }

                    return rendered_nodes.join("");
                }
            }
        },

        // Base text node object for prototyping.
        baseTextNode = {
            render: function (context) {
                return this.text || "";
            }
        },

        // Base variable node object for prototyping.
        baseVarNode = {
            render: function (context) {
                var val = context[this.name] === undefined ?
                    "" :
                    context[this.name];
                if (val === "" && this.name.search(/\./) !== -1) {
                    return getValFromObj(this.name, context);
                }
                return cleanVal(val);
            }
        };

    // CUSTOM ERRORS

    function TemplateSyntaxError(message) {
        if (!(this instanceof TemplateSyntaxError)) {
            return new TemplateSyntaxError(message);
        }
        this.message = message;
        return this;
    }
    TemplateSyntaxError.prototype = new SyntaxError();
    TemplateSyntaxError.prototype.name = "TemplateSyntaxError";

    // PRIVATE FUNCTIONS

    // Some browsers don't return the grouped part of the RegExp with the array,
    // so we must accomodate them.
    var split = (function () {
        if ("abc".split(/(b)/).length === 3) {
            return function (str, delimiter) {
                return String.prototype
                             .split
                             .call(str, delimiter);
            };
        } else {
            return function (str, delimiter) {
                if (Object.prototype
                          .toString
                          .call(delimiter) === "[object RegExp]") {
                    var regex = delimiter.ignoreCase ?
                        new RegExp(delimiter.source, "gi") :
                        new RegExp(delimiter.source, "g"),
                    match,
                    match_str = "",
                    arr = [],
                    i,
                    len = str.length;

                    for (i = 0; i < len; i++) {
                        match_str += str.charAt(i);
                        match = match_str.match(regex);
                        if (match !== null && match.length > 0) {
                            arr.push(match_str.replace(match[0], ""));
                            arr.push(match[0]);
                            match_str = "";
                        }
                    }

                    if (match_str !== "") {
                        arr.push(match_str);
                    }

                    return arr;
                } else {
                    return String.prototype
                                 .split
                                 .call(str, delimiter);
                }
            };
        }
    }());

    function isBlockTag(token) {
        return token.search(BLOCK_TAG) !== -1;
    }
    function isEndTag(token) {
        return token.search(END_BLOCK_TAG) !== -1;
    }
    function isVarTag(token) {
        return token.search(VAR_TAG) !== -1;
    }

    function strip(str) {
        return str.replace(/^[\s]+/, "").replace(/[\s]+$/, "");
    }

    // Clean the passed value the best we can.
    function cleanVal(val) {
        if (val instanceof $) {
            return jQueryToString(val);
        } else if (val !== null && !isArray(val) && typeof(val) === "object") {
            if (typeof(val.toHTML) === "function") {
                return cleanVal(val.toHTML());
            } else {
                return val.toString();
            }
        } else {
            return val;
        }
    }

    // Traverse a path of an obj from a string representation,
    // for example "object.child.attr".
    function getValFromObj(str, obj) {
        var path = split(str, "."),
            val = obj[path[0]],
            i;
        for (i = 1; i < path.length; i++) {
            // Return an empty string if the lookup ever hits undefined.
            if (val !== undefined) {
                val = val[path[i]];
            } else {
                return "";
            }
        }

        // Make sure the last piece did not end up undefined.
        val = val === undefined ? "" : val;
        return cleanVal(val);
    }

    // Hack to get the HTML of a jquery object as a string.
    function jQueryToString(jq) {
        return $(document.createElement("div")).append(jq).html();
    }

    // Make a new copy of a given object.
    function makeObj(obj) {
        if (obj === undefined) {
            return obj;
        }
        var O = function () {};
        O.prototype = obj;
        return new O();
    }

    // Return an array of key/template pairs.
    function storedTemplates() {
        var cache = [];
        $.each(templateCache, function (key, templ) {
            cache.push([ key, templ ]);
        });
        return cache;
    }

    // Determine if the string is a key to a stored template or a
    // one-time-use template.
    function chooseTemplate(str) {
        return typeof templateCache[str] === "string" ?
            templateCache[str] :
            str;
    }

    // Return true if (and only if) an object is an array.
    function isArray(objToTest) {
        return Object.prototype
                     .toString
                     .apply(objToTest) === "[object Array]";
    }

    // Call a rendering function on arrays of objects or just a single
    // object seamlessly.
    function renderEach(data, f) {
        return isArray(data) ?
            $.each(data, f) :
            f(0, data);
    }

    // Split a template in to tokens which will eventually be converted to
    // nodes and then rendered.
    function tokenize(templ) {
        return (function (arr) {
            var tokens = [];
            for (i = 0; i < arr.length; i++) {
                (function (token) {
                     return token === "" ?
                        null :
                        tokens.push(token);
                }(arr[i]));
            }
            return tokens;
        }(split(templ, new RegExp("(" + VAR_TAG.source + "|" +
                                  BLOCK_TAG.source + "|" +
                                  END_BLOCK_TAG.source + ")"))));
    }

    // "Lisp in C's clothing." - Douglas Crockford
    function cdr(arr) {
        return arr.slice(1);
    }

    // Array.push changes the original array in place and returns the new
    // length of the array rather than the the actual array itself. This
    // makes it unchainable, which is ridiculous.
    function append(item, list) {
        return list.concat([item]);
    }

    // Take a token and create a variable node from it.
    function makeVarNode(token) {
        var node = makeObj(baseVarNode);
        node.name = strip(token.replace(OPEN_VAR_TAG, "")
                               .replace(CLOSE_VAR_TAG, ""));
        return node;
    }

    // Take a token and create a text node from it.
    function makeTextNode(token) {
        var node = makeObj(baseTextNode);
        node.text = token;
        return node;
    }

    // A recursive function that terminates either when all tokens have
    // been converted to nodes or an end-block tag is found.
    function makeNodes(tokens) {
        return (function (nodes, tokens) {
            var token = tokens[0];
            return tokens.length === 0 ?
                       [nodes, [], true] :
                   isEndTag(token) ?
                       [nodes, cdr(tokens)] :
                   isVarTag(token) ?
                       arguments.callee(append(makeVarNode(token), nodes), cdr(tokens)) :
                   isBlockTag(token) ?
                       makeBlockNode(nodes, tokens, arguments.callee) :
                   // Else assume it is a text node.
                       arguments.callee(append(makeTextNode(token), nodes), cdr(tokens));

        }([], tokens));
    }

    // Split a block tags contents in to an array of bits that contains the
    // type of block node, and any arguments that were passed to the block
    // node if they exist.
    function makeBits(blockToken) {
        return (function (bits, split) {
            // Remove empty strings and strip whitespace.
            for (i = 0; i < split.length; i++) {
                (function (bit) {
                    return bit === "" ? null : bits.push(bit);
                }(strip(split[i])));
            }
            return bits;
        }([], split(blockToken.replace(OPEN_BLOCK_TAG, "")
                              .replace(CLOSE_BLOCK_TAG, ""),
                   /[\s]+?/)));
    }

    // Create a block tag's node by hijacking the "makeNodes" function
    // until an end-block is found.
    function makeBlockNode(nodes, tokens, f) {
        // Remove the templating syntax and split the type of block tag and
        // its arguments.
        var bits = makeBits(tokens[0]),

            // The type of block tag is the first of the bits, the rest
            // (if present) are args
            type = bits[0],
            args = cdr(bits),

            // Make the node from the set of block tags that Tempest knows
            // about.
            node = makeObj(BLOCK_NODES[type]),
            resultsArray;

        // Ensure that the type of block tag is one that is defined in
        // BLOCK_NODES
        if (node === undefined) {
            throw new TemplateSyntaxError("Unknown Block Tag.");
        }

        node.args = args;
        tokens = cdr(tokens);

        if (node.expectsEndTag === true) {
            resultsArray = makeNodes(tokens);

            if (resultsArray[2] !== undefined) {
                // The third item in the array returned by makeNodes is
                // only defined if the last of the tokens was made in to a
                // node and it wasn't an end-block tag.
                throw new TemplateSyntaxError(
                    "A block tag was expecting an ending tag but it was not found."
                );
            }
            node.subNodes = resultsArray[0];
            tokens = resultsArray[1];
        }

        // Add the newly created node to the nodes list.
        nodes = append(node, nodes);

        // Continue where we were before the block node.
        return f(nodes, tokens);
    }

    // Return the template rendered with the given object(s) as a jQuery
    // object.
    function renderToJQ(str, objects) {
        var template = chooseTemplate(str),
            lines = [];

        renderEach(objects, function (i, obj) {
            var resultsArray = makeNodes(tokenize(template), obj),
                nodes = resultsArray[0];

            // Check for tokens left over in the results array, this means
            // that not all tokens were rendered because there are more
            // end-block tagss than block tags that expect an end.
            if (resultsArray[1].length !== 0) {
                throw new TemplateSyntaxError(
                    "An unexpected end tag was found."
                );
            }

            // Render each node and push it to the lines.
            $.each(nodes, function (i, node) {
                lines.push(node.render(obj));
            });
        });

        // Return the joined templates as jQuery objects if it appears to start
        // with an HTML tag, otherwise just return the string itself.
        return (function (str) {
            return str.charAt(0) === "<" ?
                $(str) :
                str;
        }(strip(lines.join(""))));
    }

    // EXTEND JQUERY OBJECT
    $.extend({
        tempest: function () {
            var args = arguments;

            if (args.length === 0) {

                // Return key/template pairs of all stored templates.
                return storedTemplates();

            } else if (args.length === 2 &&
                       typeof(args[0]) === "string" &&
                       typeof(args[1]) === "object") {

                // Render the supplied template (args[0], template name of
                // existing or one-time-use template) with the context data
                // (args[1]).
                return renderToJQ(args[0], args[1]);

            } else if (args.length === 1 && typeof(args[0]) === "string") {

                // Template getter.
                return templateCache[args[0]];

            } else if (args.length === 2 &&
                       typeof(args[0]) === "string" &&
                       typeof(args[1]) === "string") {

                // Template setter.
                templateCache[args[0]] = args[1].replace(/^\s+/g, "")
                                                .replace(/\s+$/g, "")
                                                .replace(/[\n\r]+/g, "");
                return templateCache[args[0]];

            } else {

                // Raise an exception because the arguments did not match the
                // API.
                throw new TypeError(
                    "jQuery.tempest can't handle the given arguments."
                );

            }
        }
    });

    // Extend jQuery("selector").tempest using the existing jQuery.tempest API.
    $.fn.tempest = function() {
        var args = Array.prototype.slice.call(arguments, 0);
        var f = null;

        if (args.length == 2 &&
            typeof args[0] == "string" &&
            typeof args[1] == "object") {
            // Inserts the result of rendering the specified template on the
            // specified data into the set of matched elements.
            f = function () {
                $(this).html($.tempest(args[0], args[1]));
            };
        } else if (args.length == 3 &&
                   typeof args[0] == "string" &&
                   typeof args[1] == "string" &&
                   typeof args[2] == "object") {
            // Calls the appropriate jQuery function, passing it the result of
            // rendering the given template on the data provided.
            f = function () {
                $(this)[args[0]]($.tempest(args[1], args[2]));
            };
        } else {
            throw new TypeError([
                "jQuery(selector).tempest was passed the wrong number or type",
                "of arguments. Received " + args
            ].join(" "));
        }

        return this.each(f);
    };

    // EXPOSE BLOCK_NODES OBJECT TO ALLOW EXTENSION WITH CUSTOM TAGS
    $.tempest.tags = BLOCK_NODES;

    // EXPOSE PRIVATE FUNCTIONS FOR TESTING
    if (window.testTempestPrivates === true) {
        $.tempest._test = {};

        // Make it easier to attach the private methods methods to the public
        // object.
        function a(name, fn) {
            $.tempest._test[name] = fn;
        }
        a("isBlockTag", isBlockTag);
        a("isEndTag", isEndTag);
        a("isVarTag", isVarTag);
        a("cleanVal", cleanVal);
        a("getValFromObj", getValFromObj);
        a("jQueryToString", jQueryToString);
        a("makeObj", makeObj);
        a("storedTemplates", storedTemplates);
        a("chooseTemplate", chooseTemplate);
        a("isArray", isArray);
        a("renderEach", renderEach);
        a("tokenize", tokenize);
        a("cdr", cdr);
        a("append", append);
        a("makeVarNode", makeVarNode);
        a("makeTextNode", makeTextNode);
        a("makeNodes", makeNodes);
        a("makeBits", makeBits);
        a("makeBlockNode", makeBlockNode);
        a("renderToJQ", renderToJQ);
        a("strip", strip);
    }

    // GET ALL TEXTAREA TEMPLATES ON READY
    $(document).ready(function () {
        $(".tempest-template").each(function (obj) {
            templateCache[$(this).attr('title')] = strip(($(this).val() || $(this).html()).replace(/[\n\r]+/g, " "));
            $(this).remove();
        });
    });
}(jQuery));
