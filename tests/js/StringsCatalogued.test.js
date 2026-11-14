const fs = require('fs');
const path = require('path');
const { parse } = require('@babel/parser');
const traverse = require('@babel/traverse').default;

/**
 * Every user-facing string the reporting JavaScript writes goes through
 * OWA.l() or OWA.ln(), so the catalogue (conf/strings/en.php) holds it and a
 * translated install shows it translated.
 *
 * The source is parsed with @babel/parser and @babel/traverse (both installed
 * by @babel/core). A string or template literal that reads as words fails
 * where it reaches the page:
 *
 *   - text: .text(), textContent, innerText, createTextNode(), new Option(),
 *     confirm() / alert() / prompt(), insertAdjacentText(), and the
 *     title / aria-label / placeholder / alt attributes (.attr(), .prop(),
 *     setAttribute(), or assigned as a property)
 *   - HTML: .html(), .append(), .prepend(), .before(), .after(),
 *     .replaceWith(), insertAdjacentHTML(), innerHTML / outerHTML,
 *     jQuery('<…>'), and any literal that holds markup: its text nodes and
 *     those attributes; what is added to a variable that holds markup is
 *     markup too
 *   - the text / html / title / label / … property of an object literal (and
 *     a property of that name assigned), when the value is more than one word
 *     or a capitalised word
 *   - an argument of a function in the same file that puts that parameter in
 *     one of the places above (a helper such as showError( message )), and
 *     what a same-file function returns when its result reaches one
 *
 * A literal reaches a place through concatenation, a template, a conditional,
 * ||, sprintf(), an escaping call, a local variable (every value it is given)
 * or a property assigned in the same file (this.badInput, TEXT.label).
 *
 * A literal passed to OWA.l(), OWA.ln(), OWA.translate() or
 * OWA.translatePlural() is catalogued, and so is anything inside that call.
 * console.* and OWA.debug() messages are for developers. Strings::LABEL_TABLES
 * objects are catalogued by the extractor and read as data elsewhere.
 *
 * Also: OWA.l() is not called while the script loads (the page sets
 * OWA.config.strings after the bundle runs), and every OWA.l() / OWA.ln()
 * key is in conf/strings/script-keys.php.
 */

const ROOT = path.join(__dirname, '../..');

/** Sources not scanned, and why. */
const EXCLUDED = [
    { test: /\/src\/tracker\//, why: 'the tracker bundles run on tracked sites and do not load OWA.l()' },
    { test: /\/src\/common\//, why: 'shared with the tracker bundles; no user-facing text' },
    { test: /\/vendor-jquery-global\.js$/, why: 'exposes the vendor jQuery as a global' },
];

/**
 * Literals that read as words but are not text for a person to read:
 * { file, text, why }, matched by file name and the text as the failure
 * shows it. An entry nothing matches fails the scan.
 */
const ALLOWED = [
];

/** The ALLOWED entries a scan matched. */
const allowedSeen = new Set();

/** Attributes a person reads, including data-* ones that script copies to the page (data-owa-confirm-body). */
const TEXT_ATTRIBUTE = /^(title|alt|placeholder|aria-(label|description|roledescription|valuetext)|data-[\w-]*(label|title|body|text|placeholder|proceed|cancel|help|message))$/i;
const TEXT_PROPERTIES = ['textContent', 'innerText', 'title', 'placeholder', 'ariaLabel', 'alt', 'ariaDescription', 'ariaRoleDescription', 'ariaValueText'];
const HTML_PROPERTIES = ['innerHTML', 'outerHTML'];
const HTML_METHODS = ['html', 'append', 'prepend', 'before', 'after', 'replaceWith'];
/** Object keys and properties whose value is shown: label, ariaLabel, no_results_text, emptyMessage. */
const OBJECT_KEY = /^(text|html|title|label|placeholder|alt|tooltip|message|body|proceed|cancel|heading|caption|aria-label)$|(^|_)(text|label|title|placeholder|message)$|[a-z](Text|Label|Title|Placeholder|Message)$/;
const DIALOGS = ['confirm', 'alert', 'prompt', 'window.confirm', 'window.alert', 'window.prompt'];
const TRANSLATORS = ['OWA.l', 'OWA.ln', 'OWA.translate', 'OWA.translatePlural'];
const ESCAPES = ['esc', 'escapeHtml', 'OWA.util.escapeHtml', 'String'];
const LABEL_TABLES = ['OWA.dataGrid.EMPTY_TESTS', 'OWA.dataGrid.OPERATOR_PHRASES', 'OWA.dataGrid.CONDITIONS', 'OWA.filterPills.OPERATORS', 'OWA.filterPills.EMPTY_TESTS'];

/** A part of a flattened string not known here. */
const HOLE = '\u0000';

/** A parameter's place in a flattened string, while finding helpers. */
const MARK = '\u0001';

/** A same-file function's result in a flattened string. */
const CALL = '\u0002';

/** A printf placeholder. */
const PLACEHOLDER = /%(\d+\$)?[-+ 0#']*\d*(\.\d+)?[sdfuxXoceEgGb]/g;

/** Words, in a place that is text: any run of two letters. */
const WORD = /\p{L}{2,}/u;

/** Words, in a place that may hold data: two of them, or one capitalised. */
const PHRASE = /\p{L}{2,}[\s\u00a0]+\p{L}{2,}|^[\s\u0000]*\p{Lu}\p{Ll}+/u;

function memberName(node) {
    if (node && (node.type === 'MemberExpression' || node.type === 'OptionalMemberExpression')) {
        if (!node.computed && node.property.type === 'Identifier') {
            return node.property.name;
        }
        if (node.computed && node.property.type === 'StringLiteral') {
            return node.property.value;
        }
    }
    return null;
}

/** The dotted name of an identifier or member chain: OWA.util.sprintf. */
function dotted(node) {
    if (!node) {
        return null;
    }
    if (node.type === 'Identifier') {
        return node.name;
    }
    if (node.type === 'ThisExpression') {
        return 'this';
    }
    const name = memberName(node);
    const object = name !== null ? dotted(node.object) : null;
    return object !== null ? object + '.' + name : null;
}

function calleeName(node) {
    return node && (node.type === 'CallExpression' || node.type === 'OptionalCallExpression') ? dotted(node.callee) : null;
}

/** The name a function is called by: its own, its variable's, or its property's. */
function functionName(p) {
    const node = p.node;
    const parent = p.parent;
    if (node.id) {
        return node.id.name;
    }
    if ((node.type === 'ObjectMethod' || node.type === 'ClassMethod') && !node.computed) {
        return node.key.name || node.key.value || null;
    }
    if (parent.type === 'VariableDeclarator' && parent.id.type === 'Identifier') {
        return parent.id.name;
    }
    if (parent.type === 'ObjectProperty' && parent.value === node && !parent.computed) {
        return parent.key.name || parent.key.value || null;
    }
    if (parent.type === 'AssignmentExpression' && parent.right === node) {
        return memberName(parent.left) || (parent.left.type === 'Identifier' ? parent.left.name : null);
    }
    return null;
}

/** A string's text with placeholders and character references taken out. */
function bare(s) {
    return s.replace(PLACEHOLDER, HOLE).replace(/%%/g, '%').replace(/&(#\d+|#x[0-9a-f]+|[a-z]+);/gi, ' ');
}

function looksLikeHtml(s) {
    return /<\/?[a-zA-Z][\w-]*([\s>/\u0000\u0001]|$)/.test(s);
}

/**
 * The text a person reads in a piece of markup: its text nodes and its
 * text attributes. A piece that closes a tag before opening one starts
 * inside a tag (it continues markup built before it).
 */
function htmlText(s) {
    const out = [];
    const firstOpen = s.indexOf('<');
    const firstClose = s.indexOf('>');
    const attributes = /(?:^|\s)([\w-]+)\s*=\s*(["'])([^"']*)\2/g;
    let inTag = firstClose !== -1 && (firstOpen === -1 || firstClose < firstOpen);
    let text = '';
    let tag = '';
    let skip = false;

    for (const c of s) {
        if (!inTag && c === '<') {
            if (!skip) {
                out.push(text);
            }
            text = '';
            tag = '';
            inTag = true;
        } else if (inTag && c === '>') {
            let m;
            while ((m = attributes.exec(tag)) !== null) {
                if (TEXT_ATTRIBUTE.test(m[1])) {
                    out.push(m[3]);
                }
            }
            skip = /^(script|style)\b/i.test(tag);
            inTag = false;
        } else if (inTag) {
            tag += c;
        } else {
            text += c;
        }
    }

    if (!inTag && !skip) {
        out.push(text);
    }

    return out;
}

/** Each place in one source where a literal reaches the page untranslated. */
function scanSource(source, file) {
    const ast = parse(source, { sourceType: 'module' });
    const fileName = path.basename(file);
    const found = new Map();

    // Properties assigned in this file, by dotted name: this.badInput, TEXT.label.
    const members = new Map();
    // Functions with a name, and which of their parameters each puts on the page.
    const functions = [];
    const helpers = new Map();
    // Same-file functions whose result reaches the page.
    const returning = new Set();
    // While finding helpers: the function's parameter bindings, and those that reached the page.
    let probe = null;

    const addMember = (name, p) => {
        if (!members.has(name)) {
            members.set(name, []);
        }
        members.get(name).push(p);
        if (p.isObjectExpression()) {
            for (const prop of p.get('properties')) {
                if (prop.isObjectProperty() && !prop.node.computed) {
                    addMember(name + '.' + (prop.node.key.name || prop.node.key.value), prop.get('value'));
                }
            }
        }
    };

    traverse(ast, {
        AssignmentExpression(p) {
            const name = dotted(p.node.left);
            if (p.node.operator === '=' && name && p.node.left.type === 'MemberExpression') {
                addMember(name, p.get('right'));
            }
        },
        VariableDeclarator(p) {
            if (p.node.id.type === 'Identifier' && p.node.init && p.node.init.type === 'ObjectExpression') {
                addMember(p.node.id.name, p.get('init'));
            }
        },
        Function(p) {
            const name = functionName(p);
            if (name) {
                functions.push({ name, path: p });
            }
        },
    });

    const functionNames = new Set(functions.map((f) => f.name));
    const cap = (list) => list.slice(0, 48);
    const join = (a, b) => ({ s: a.s + b.s, lits: a.lits.concat(b.lits) });
    const hole = () => [{ s: HOLE, lits: [] }];

    /** The values a name is given in this file: a binding's, or a property's. */
    const valuesOf = (p, seen) => {
        if (p.isIdentifier()) {
            const binding = p.scope.getBinding(p.node.name);
            if (!binding) {
                return null;
            }
            if (probe && probe.params.has(binding)) {
                return [{ s: MARK + probe.params.get(binding) + MARK, lits: [] }];
            }
            if (seen.has(binding)) {
                return null;
            }
            seen.add(binding);
            const values = [];
            if (binding.path.isVariableDeclarator() && binding.path.node.init) {
                values.push(binding.path.get('init'));
            }
            for (const v of binding.constantViolations) {
                if (v.isAssignmentExpression() && v.node.operator === '=') {
                    values.push(v.get('right'));
                }
            }
            return values;
        }
        const name = dotted(p.node);
        if (name && members.has(name) && !seen.has(name)) {
            seen.add(name);
            return members.get(name);
        }
        return null;
    };

    /**
     * The strings an expression can be: { s, lits }, s with HOLE for a part
     * not known here, lits the literals it is made of. A translated call is
     * a HOLE: what it holds is catalogued.
     */
    const flatten = (p, seen = new Set()) => {
        const node = p.node;

        if (seen.size > 8) {
            return hole();
        }

        switch (node.type) {
            case 'StringLiteral':
                return [{ s: node.value, lits: [node] }];

            case 'TemplateLiteral': {
                let out = [{ s: node.quasis[0].value.cooked || '', lits: [node] }];
                p.get('expressions').forEach((e, i) => {
                    const parts = flatten(e, seen);
                    const next = { s: node.quasis[i + 1].value.cooked || '', lits: [] };
                    out = cap(out.flatMap((a) => parts.map((b) => join(join(a, b), next))));
                });
                return out;
            }

            case 'BinaryExpression':
                if (node.operator === '+') {
                    const left = flatten(p.get('left'), seen);
                    const right = flatten(p.get('right'), seen);
                    return cap(left.flatMap((a) => right.map((b) => join(a, b))));
                }
                return hole();

            case 'ConditionalExpression':
                return cap([...flatten(p.get('consequent'), seen), ...flatten(p.get('alternate'), seen)]);

            case 'LogicalExpression':
                return cap([...flatten(p.get('left'), seen), ...flatten(p.get('right'), seen)]);

            case 'ParenthesizedExpression':
            case 'TSAsExpression':
                return flatten(p.get('expression'), seen);

            case 'Identifier':
            case 'MemberExpression': {
                const values = valuesOf(p, seen);
                if (values === null || values.length === 0) {
                    return hole();
                }
                if (values[0].s !== undefined) {
                    return values;
                }
                return cap(values.flatMap((v) => flatten(v, seen)));
            }

            case 'CallExpression': {
                const name = calleeName(node);
                const args = p.get('arguments');
                if (TRANSLATORS.includes(name)) {
                    return hole();
                }
                if (name && /(^|\.)sprintf$/.test(name) && args.length) {
                    return flatten(args[0], seen).map((a) => ({ s: a.s.replace(PLACEHOLDER, HOLE), lits: a.lits }));
                }
                if (ESCAPES.includes(name) && args.length) {
                    return flatten(args[0], seen);
                }
                const method = memberName(node.callee);
                const object = p.get('callee.object');
                if (method === 'join' && object.isArrayExpression()) {
                    const sep = node.arguments[0] && node.arguments[0].type === 'StringLiteral' ? node.arguments[0].value : ',';
                    return [object.get('elements').map((e) => flatten(e, seen)[0]).reduce((a, b) => join(join(a, { s: sep, lits: [] }), b))];
                }
                if (['replace', 'replaceAll', 'concat', 'trim'].includes(method) && object.node
                    && ['StringLiteral', 'TemplateLiteral', 'BinaryExpression'].includes(object.node.type)) {
                    return flatten(object, seen);
                }
                const own = method || name;
                if (own && functionNames.has(own)) {
                    return [{ s: CALL + own + CALL, lits: [] }];
                }
                return hole();
            }

            default:
                return hole();
        }
    };

    /** A piece that reads as words: a failure, or while finding helpers, the parameters in it. */
    const words = (p, piece, alt, rule) => {
        for (const m of piece.matchAll(new RegExp(CALL + '([^' + CALL + ']+)' + CALL, 'g'))) {
            returning.add(m[1]);
        }
        piece = piece.replace(new RegExp(CALL + '[^' + CALL + ']+' + CALL, 'g'), HOLE);
        if (probe) {
            if (rule === WORD) {
                for (const m of piece.matchAll(new RegExp(MARK + '(\\d+)' + MARK, 'g'))) {
                    probe.hits.add(Number(m[1]));
                }
            }
            return;
        }
        if (!rule.test(bare(piece).replace(new RegExp(MARK + '\\d+' + MARK, 'g'), HOLE))) {
            return;
        }
        const shown = piece.split(HOLE).join('…').trim();
        const allowed = ALLOWED.find((a) => a.file === fileName && a.text === shown);
        if (allowed) {
            allowedSeen.add(allowed);
            return;
        }
        const firstWord = (bare(piece).match(/\p{L}{2,}/u) || [''])[0];
        const lit = alt.lits.find((l) => (l.type === 'StringLiteral' ? l.value : l.quasis.map((q) => q.value.cooked).join(' ')).includes(firstWord));
        const line = (lit || p.node).loc.start.line;
        const key = line + ':' + shown;
        if (!found.has(key)) {
            found.set(key, { file, line, text: shown });
        }
    };

    const asText = (p) => {
        for (const alt of flatten(p)) {
            if (looksLikeHtml(alt.s)) {
                htmlText(alt.s).forEach((piece) => words(p, piece, alt, WORD));
            } else {
                words(p, alt.s, alt, WORD);
            }
        }
    };

    const asHtml = (p, onlyMarkup) => {
        for (const alt of flatten(p)) {
            if (looksLikeHtml(alt.s)) {
                htmlText(alt.s).forEach((piece) => words(p, piece, alt, WORD));
            } else if (!onlyMarkup) {
                words(p, alt.s, alt, WORD);
            }
        }
    };

    const asPhrase = (p) => {
        for (const alt of flatten(p)) {
            if (looksLikeHtml(alt.s)) {
                htmlText(alt.s).forEach((piece) => words(p, piece, alt, WORD));
            } else {
                words(p, alt.s, alt, PHRASE);
            }
        }
    };

    /** Does a name hold markup: is it ever given a value with a tag in it? */
    const holdsMarkup = (p) => {
        const values = valuesOf(p, new Set());
        return values !== null && values.length > 0 && values[0].s === undefined
            && values.some((v) => flatten(v).some((alt) => looksLikeHtml(alt.s)));
    };

    const roots = new Set();

    const visitor = {
        CallExpression(p) {
            const name = calleeName(p.node);
            const method = memberName(p.node.callee);
            const args = p.get('arguments');

            if (TRANSLATORS.includes(name) || /^console\./.test(name || '') || name === 'OWA.debug') {
                p.skip();
                return;
            }

            if (DIALOGS.includes(name) || name === 'document.createTextNode') {
                args[0] && asText(args[0]);
            } else if ((name === 'jQuery' || name === '$') && args[0]) {
                asHtml(args[0], true);
            } else if (method === 'text' && args.length === 1) {
                asText(args[0]);
            } else if (HTML_METHODS.includes(method)) {
                args.forEach((a) => asHtml(a));
            } else if (['attr', 'prop', 'setAttribute'].includes(method) && args.length >= 2
                && args[0].isStringLiteral() && TEXT_ATTRIBUTE.test(args[0].node.value)) {
                asText(args[1]);
            } else if (method === 'insertAdjacentHTML' && args[1]) {
                asHtml(args[1]);
            } else if (method === 'insertAdjacentText' && args[1]) {
                asText(args[1]);
            }

            const helper = helpers.get(method || name);
            if (helper) {
                helper.forEach((i) => args[i] && asText(args[i]));
            }
        },

        NewExpression(p) {
            if (dotted(p.node.callee) === 'Option' && p.node.arguments[0]) {
                asText(p.get('arguments.0'));
            }
        },

        AssignmentExpression(p) {
            if (LABEL_TABLES.includes(dotted(p.node.left))) {
                p.skip();
                return;
            }
            if (p.node.operator !== '=' && p.node.operator !== '+=') {
                return;
            }
            const prop = memberName(p.node.left);
            const right = p.get('right');
            if (TEXT_PROPERTIES.includes(prop)) {
                asText(right);
            } else if (HTML_PROPERTIES.includes(prop)) {
                asHtml(right);
            } else if (prop && OBJECT_KEY.test(prop)) {
                asPhrase(right);
            } else if (p.node.operator === '+=' && holdsMarkup(p.get('left'))) {
                asHtml(right);
            }
        },

        ObjectProperty(p) {
            const key = p.node.computed ? null : (p.node.key.name || p.node.key.value);
            if (key && OBJECT_KEY.test(key)) {
                asPhrase(p.get('value'));
            }
        },

        // A literal holding markup, wherever it is: the whole concatenation it is part of.
        'StringLiteral|TemplateLiteral'(p) {
            if ((p.parentPath.isObjectProperty() && p.parent.key === p.node) || p.parentPath.isImportDeclaration()
                || p.parentPath.isExportDeclaration()) {
                return;
            }
            let root = p;
            while ((root.parentPath.isBinaryExpression({ operator: '+' })) || root.parentPath.isTemplateLiteral()) {
                root = root.parentPath;
            }
            if (roots.has(root.node)) {
                return;
            }
            roots.add(root.node);
            asHtml(root, true);
        },
    };

    // Helpers first: a function that puts a parameter on the page, and the ones that pass it on to one.
    for (let round = 0; round < 6; round++) {
        let changed = false;
        for (const fn of functions) {
            const params = new Map();
            fn.path.get('params').forEach((param, i) => {
                const id = param.isAssignmentPattern() ? param.get('left') : param;
                if (id.isIdentifier()) {
                    params.set(fn.path.scope.getBinding(id.node.name), i);
                }
            });
            if (params.size === 0) {
                continue;
            }
            probe = { params, hits: new Set() };
            roots.clear();
            fn.path.traverse(visitor);
            const known = helpers.get(fn.name) || new Set();
            for (const i of probe.hits) {
                if (!known.has(i)) {
                    known.add(i);
                    changed = true;
                }
            }
            if (known.size) {
                helpers.set(fn.name, known);
            }
        }
        if (!changed) {
            break;
        }
    }

    probe = null;
    roots.clear();
    traverse(ast, visitor);

    // A function whose result reaches the page: what it returns is text.
    const done = new Set();
    for (let more = true; more;) {
        more = false;
        for (const fn of functions) {
            if (returning.has(fn.name) && !done.has(fn)) {
                done.add(fn);
                more = true;
                fn.path.traverse({
                    Function(q) {
                        q.skip();
                    },
                    ReturnStatement(q) {
                        if (q.node.argument) {
                            asText(q.get('argument'));
                        }
                    },
                });
            }
        }
    }

    return [...found.values()];
}


/** The reporting UI's sources: modules/<module>/src/**.js less EXCLUDED. */
function uiSources() {
    const out = [];
    const walk = (dir) => {
        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
            const full = path.join(dir, entry.name);
            if (entry.isDirectory()) {
                walk(full);
            } else if (entry.name.endsWith('.js') && !EXCLUDED.some((e) => e.test.test(full))) {
                out.push(full);
            }
        }
    };
    for (const module of fs.readdirSync(path.join(ROOT, 'modules'))) {
        const src = path.join(ROOT, 'modules', module, 'src');
        if (fs.existsSync(src)) {
            walk(src);
        }
    }
    return out.sort();
}

/** The catalogue key of each OWA.l() / OWA.ln() call in a source; null where the text is not a literal. */
function translatedKeys(source) {
    const ast = parse(source, { sourceType: 'module' });
    const keys = [];
    const literal = (node) => {
        if (!node) {
            return null;
        }
        if (node.type === 'StringLiteral') {
            return node.value;
        }
        if (node.type === 'TemplateLiteral' && node.expressions.length === 0) {
            return node.quasis[0].value.cooked;
        }
        if (node.type === 'BinaryExpression' && node.operator === '+') {
            const l = literal(node.left);
            const r = literal(node.right);
            return l !== null && r !== null ? l + r : null;
        }
        return null;
    };
    const visit = (node) => {
        if (!node || typeof node.type !== 'string') {
            return;
        }
        const name = node.type === 'CallExpression' ? dotted(node.callee) : null;
        if (name === 'OWA.l' || name === 'OWA.ln') {
            const text = literal(node.arguments[0]);
            const contextArg = node.arguments[name === 'OWA.l' ? 1 : 3];
            const context = contextArg ? literal(contextArg) : '';
            keys.push({
                line: node.loc.start.line,
                key: text === null || context === null ? null : (context ? context + '\x04' + text : text),
            });
        }
        for (const key of Object.keys(node)) {
            const child = node[key];
            if (key === 'loc') {
                continue;
            }
            if (Array.isArray(child)) {
                child.forEach(visit);
            } else if (child && typeof child.type === 'string') {
                visit(child);
            }
        }
    };
    visit(ast.program);
    return keys;
}

/**
 * Each OWA.l() / OWA.ln() call made when the script loads, outside any
 * function: the bundle runs before config_dom.php sets OWA.config.strings,
 * so such a call always answers the English.
 */
function loadTimeCalls(source) {
    const ast = parse(source, { sourceType: 'module' });
    const out = [];
    traverse(ast, {
        Function(p) {
            p.skip();
        },
        CallExpression(p) {
            if (['OWA.l', 'OWA.ln'].includes(calleeName(p.node))) {
                out.push(p.node.loc.start.line);
            }
        },
    });
    return out;
}

/** conf/strings/script-keys.php's keys. */
function scriptKeys() {
    const source = fs.readFileSync(path.join(ROOT, 'conf/strings/script-keys.php'), 'utf8');
    const keys = new Set();
    for (const line of source.split('\n')) {
        const m = line.match(/^\s+('(?:[^'\\]|\\.)*')(?:\s*\.\s*"\\x04"\s*\.\s*('(?:[^'\\]|\\.)*'))?,$/);
        if (m) {
            const unquote = (q) => q.slice(1, -1).replace(/\\([\\'])/g, '$1');
            keys.add(m[2] ? unquote(m[1]) + '\x04' + unquote(m[2]) : unquote(m[1]));
        }
    }
    return keys;
}

const relative = (file) => path.relative(ROOT, file);

describe('the reporting JavaScript', () => {

    test('the sources are found, and the excluded ones are not scanned', () => {
        const files = uiSources().map(relative);

        expect(files).toContain('modules/Base/src/reporting/v1/owa.periodpicker.js');
        expect(files.filter((f) => f.includes('/tracker/') || f.includes('/common/'))).toEqual([]);
    });

    test('writes no user-facing string that is not catalogued', () => {
        const problems = uiSources().flatMap((file) => scanSource(fs.readFileSync(file, 'utf8'), relative(file)))
            .map((p) => `${p.file}:${p.line}: "${p.text}" -- wrap it in OWA.l()`);

        expect(problems).toEqual([]);

        // An entry nothing matches any more is taken out.
        expect(ALLOWED.filter((a) => !allowedSeen.has(a))).toEqual([]);
    });

    test('translates when the text is used, not when the script loads', () => {
        const problems = uiSources().flatMap((file) => loadTimeCalls(fs.readFileSync(file, 'utf8'))
            .map((line) => `${relative(file)}:${line}: OWA.l() runs before the page sets OWA.config.strings -- call it where the text is used`));

        expect(problems).toEqual([]);
    });

    test('every OWA.l() and OWA.ln() key is in conf/strings/script-keys.php', () => {
        const known = scriptKeys();
        const missing = [];

        expect(known.size).toBeGreaterThan(100);

        for (const file of uiSources()) {
            for (const { line, key } of translatedKeys(fs.readFileSync(file, 'utf8'))) {
                if (key === null) {
                    missing.push(`${relative(file)}:${line}: the text is not a string literal`);
                } else if (!known.has(key)) {
                    missing.push(`${relative(file)}:${line}: "${key}" -- run php cli.php cmd=strings-extract`);
                }
            }
        }

        expect(missing).toEqual([]);
    });
});

describe('the guard', () => {

    test('finds a translation made when the script loads', () => {
        expect(loadTimeCalls("const LABEL = OWA.l('Hour');\nfunction label() { return OWA.l('Day'); }")).toEqual([1]);
    });

    const lines = (source) => scanSource(source, 'fixture.js').map((p) => p.line + ': ' + p.text);

    test.each([
        ['.text()', "$('.x').text('Loading data');", ['1: Loading data']],
        ['a lone word as text', "el.text('none');", ['1: none']],
        ['textContent', "el.textContent = 'No rows';", ['1: No rows']],
        ['innerHTML', "el.innerHTML = '<b>Total</b>';", ['1: Total']],
        ['a title attribute', "el.setAttribute('title', 'Close');", ['1: Close']],
        ['.attr aria-label', "$(el).attr('aria-label', 'Hide this column');", ['1: Hide this column']],
        ['a title property', "button.title = 'Apply';", ['1: Apply']],
        ['confirm()', "if (confirm('Delete this?')) {}", ['1: Delete this?']],
        ['window.alert()', "window.alert('Saved');", ['1: Saved']],
        ['a fragment around a count', "$(el).html('<span>' + n + ' rows</span>');", ['1: … rows']],
        ['markup built up in a variable', "var h = '<div class=\"a\">';\nh += 'Nothing to show';\nh += '</div>';", ['2: Nothing to show']],
        ['markup in a template literal', 'const h = `<p class="x">${n} visits</p>`;', ['1: … visits']],
        ['a text attribute in markup', "var h = '<button title=\"Remove\"></button>';", ['1: Remove']],
        ['jQuery() with markup', "$('<button>Apply</button>');", ['1: Apply']],
        ['an untranslated sprintf format', "el.text(OWA.util.sprintf('%s of %s', a, b));", ['1: … of …']],
        ['one branch of a conditional', "el.text(on ? OWA.l('Show') : 'Hide');", ['1: Hide']],
        ['an object label', "var o = { label: 'Page Views' };", ['1: Page Views']],
        ['a capitalised word as a label', "chart({ title: 'Visits' });", ['1: Visits']],
        ['an option', "new Option('Any value', '');", ['1: Any value']],
        ['a helper that sets text', "var ui = { text: function (sel, value) { document.querySelector(sel).textContent = value; } };\nui.text('.owa_count', 'None yet');", ['2: None yet']],
        ['a helper that passes it on', "function show(m) { $('.e').text(m); }\nfunction fail(m) { show(m); }\nfail('Could not save');", ['3: Could not save']],
        ['a variable given text', "var note = '';\nif (x) { note = 'No locations'; }\nel.text(note);", ['2: No locations']],
        ['a property given text', "this.badInput = 'Bad date';\n$(el).text(this.badInput);", ['1: Bad date']],
        ['a constant object', "const TEXT = { help: 'A %s event' };\nel.text(OWA.util.sprintf(TEXT.help, n));", ['1: A … event']],
        ['a function whose result is shown', "function why() { if (bad) { return 'Not a date'; } return ''; }\nel.html('<p>' + why() + '</p>');", ['1: Not a date']],
        ['a data attribute script shows', "$(el).attr('data-owa-confirm-body', 'It stops working.');", ['1: It stops working.']],
        ['a widget option', "$(el).chosen({ no_results_text: 'Name not found.' });", ['1: Name not found.']],
        ['an escaped literal in markup', "var h = '<b>' + esc('Custom range') + '</b>';", ['1: Custom range']],
        ['a dialog body', "open({ body: 'This cannot be undone.', proceed: 'Delete' });", ['1: This cannot be undone.', '1: Delete']],
        ['a label property', "this.label = 'Default label';", ['1: Default label']],
    ])('fails %s', (_, source, expected) => {
        expect(lines(source)).toEqual(expected);
    });

    test.each([
        ['OWA.l()', "$('.x').text(OWA.l('Loading data'));"],
        ['OWA.ln() with sprintf', "el.text(OWA.util.sprintf(OWA.ln('%d row', '%d rows', n), n));"],
        ['markup inside OWA.l()', "el.html(OWA.l('<b>%s</b> rows'));"],
        ['markup around translated text', "el.html('<b>' + OWA.l('Total') + '</b>');"],
        ['a selector', "$('.owa_report > .title span').hide();"],
        ['a class name', "el.addClass('is-open hidden');"],
        ['a URL', "el.attr('href', 'https://example.com/a b');"],
        ['an event name', "el.on('click keydown', fn);"],
        ['a data key', "el.data('report id', 3); var o = { label: 'visits' };"],
        ['a console message', "console.log('Loading data from the server');"],
        ['a debug message', "OWA.debug('Grid has no rows to show');"],
        ['markup without text', "el.html('<div class=\"a b\"><span></span></div>');"],
        ['punctuation and numbers', "el.text(n + ' %'); el.text('—'); el.html('&nbsp;&times;');"],
        ['a format with only placeholders', "el.text(OWA.util.sprintf('%s: %s', a, b));"],
        ['a text getter', "var t = el.text();"],
        ['a helper given a number', "var ui = { text: function (sel, value) { document.querySelector(sel).textContent = value; } };\nui.text('.owa_count is-on', ui.number(n));"],
        ['a function whose result is a class', "function cls() { return 'is on'; }\nel.html('<p class=\"' + cls() + '\"></p>');"],
        ['a variable given a translation', "var note = '';\nif (x) { note = OWA.l('No locations'); }\nel.text(note);"],
        ['a label table', "OWA.filterPills.OPERATORS = { '==': 'is', '!=': 'is not' };"],
        ['a non-text attribute', "el.attr('role', 'tab panel'); el.setAttribute('data-name', 'Visits');"],
    ])('allows %s', (_, source) => {
        expect(lines(source)).toEqual([]);
    });
});
