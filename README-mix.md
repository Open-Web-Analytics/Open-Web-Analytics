# Javascript and CSS packaging

There are several CSS and JS files that are made by processing and
combining several other files. This was previously done by the cli.php
script but is now done with Laravel Mix which provides a cleaner, simpler
API to using Webpack.

It is configured in `webpack.mix.js`

## Locations of files

This could probably do with a bit more optimising. When using bundlers
it's typical to keep a strict separation of source and destination output
files, but we're coming from a position where outputs were saved into the
same folder as sources.

Output CSS, JS is now found in the following places.

- `modules/css/`  
   Contains output CSS only.

- `modules/base/js/`  
   Contains output JS only.
   I kept this as-was since changing it
   requires changing the tracking snippet in other websites, which are out of
   our control.

Source CSS, JS is now found in:

- `modules/base/css`
   Original location. 3rd party library code (and assets) remain here.

- `modules/base/sass`
   New location for all OWA CSS. These are SASS .scss files.

- `modules/base/js-src`  
   This contains all source JS, including third party code.


Separation of 3rd party and OWA code: packaged CSS and JS is, as was before,
assumed to be already optimised. e.g. the .min.js files etc. These files are
not altered by Mix except to be combined into a single download. This does add
a slight HTTP overhead because now we have two files to fetch, but is generally
thought to be better practice, and more performant if the OWA file was to
change more frequently than the library code. In reality this hardly matters,
and the primary benefits are convenience and clarity. (It would be quite
possible to combine those into a single file again if this was considered
important.)

## How to compile the CSS, JS

### Initial setup

[Install node](https://nodejs.org/en/). Then from your project root in your development environment, run:

```bash
npm install
```

This will download a load of code to a dir called `node_modules`. Follow the
next step to compile the sources. Note that the first time you do this it may
download some extra code; this should be a one-off part of the initial setup.

### Compiling

From your project root on your development environment run **one of**

```bash
# To quickly compile stuff for local testing:
npm run dev
# To quickly compile stuff and to watch the source files for changes
# and immediately re-compile when they change:
npm run watch
# To prepare CSS and JS for a production commit.
npm run prod
```

The production mode will enable minification of the code and therefore takes
longer. You should see output like:

```
DONE  Compiled successfully in 14396ms

                                                          Asset       Size  Chunks             Chunk Names
              /modules/base/css/owa.reporting-combined-libs.css     36 KiB          [emitted]
   /modules/base/js/includes/jquery/jQote2/jquery.jqote2.min.js   1.88 KiB          [emitted]
/modules/base/js/includes/jquery/jquery-ui-1.8.12.custom.min.js    204 KiB          [emitted]
                                /modules/base/js/owa.tracker.js   55.5 KiB       0  [emitted]  /modules/base/js/owa.tracker
                    /modules/base/js/owa.tracker.js.LICENSE.txt  738 bytes          [emitted]
                              /modules/js/owa.reporting-libs.js    563 KiB          [emitted]
                                   /modules/js/owa.reporting.js   65.9 KiB       1  [emitted]  /modules/js/owa.reporting
                       /modules/js/owa.reporting.js.LICENSE.txt   1.48 KiB          [emitted]
                                       modules/base/css/owa.css   7.83 KiB       0  [emitted]  /modules/base/js/owa.tracker
                    modules/base/css/owa.reporting-combined.css   26.2 KiB       0  [emitted]  /modules/base/js/owa.tracker
```


## How are things combined and compiled now?

The `webpack.mix.js` file holds most of the details for this. Of note:

- `combine([source1, source2], output)` simply concatenates files; no optimisation; no alterations.
- `copy(source, output)` simply copies a file; no optimisation; no alterations.
- `js([source1, source2], output)` will concatenate, Babel-ify ES6 for old browsers and minify the code.
- `sass(source, output)` will process the source with SASS, add CSS-3 prefixes were necessary and output to output.

[SASS](https://sass-lang.com/) is a light scripted way to write CSS in
a cleaner fashion, that is fully compatible with plain CSS. It has some useful
features like importing from other files, using mixins, nested rules,
variables. The `owa.reporting-combined.scss` file (currently) only has
`@import` directives. We could have combined these files using Mix,
however the benefit of doing it from within SASS is that if one day we
want to add `$primaryThemeColor: #fff000;` we could then use that
throughout the separate .scss files.

Nb. "Sass" is the project name and it allows two formats of files. `.scss`
is what we use here (and is most common). Nesting is done with `{}`.
`.sass` files (not used in this project) are the same but indentation is
used to determine nesting.
