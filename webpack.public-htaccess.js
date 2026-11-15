// public/.htaccess, written by every build.
//
// The root .htaccess denies everything and grants public/ back with an <If>
// expression. A server that reads Require but not <If> -- shared hosting running
// LiteSpeed (#1401) -- denied every stylesheet and script, and the pages loaded
// with no styles. A directory's own Require replaces the one it inherits, so this
// file grants public/ with no expression to evaluate.
//
// Written by the build rather than committed: public/ is build output, ignored as
// a whole, and the release tarball ships what the build leaves there.

const fs = require('fs');
const path = require('path');

const RULES = `# Written by the build (webpack.public-htaccess.js). Edits here are overwritten.
#
# The built assets: the stylesheets, scripts, fonts and images the browser fetches.
# The root .htaccess denies everything and grants this tree back with an <If>
# expression; this grants it on a server that reads Require but not <If>.
<IfModule mod_authz_core.c>
    Require all granted
</IfModule>
`;

/** Write public/.htaccess under root, unless it already says exactly this. */
function writePublicHtaccess(root) {
	const file = path.join(root, 'public', '.htaccess');

	if (fs.existsSync(file) && fs.readFileSync(file, 'utf8') === RULES) {
		return file;
	}

	fs.mkdirSync(path.dirname(file), { recursive: true });
	fs.writeFileSync(file, RULES);

	return file;
}

/** A webpack plugin that writes it once the build has emitted its files. */
class PublicHtaccessPlugin {
	constructor(root) {
		this.root = root;
	}

	apply(compiler) {
		compiler.hooks.afterEmit.tap('PublicHtaccessPlugin', () => {
			writePublicHtaccess(this.root);
		});
	}
}

module.exports = { RULES, writePublicHtaccess, PublicHtaccessPlugin };
