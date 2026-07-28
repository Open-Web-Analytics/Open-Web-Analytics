// Babel config used ONLY by jest to transform the tracker's ES-module source
// (modules/base/src/**) into CommonJS for the node test environment. The
// production bundle is built by webpack, which handles ESM natively and does
// not use this config.
module.exports = {
    presets: [
        ['@babel/preset-env', { targets: { node: 'current' } }],
    ],
};
