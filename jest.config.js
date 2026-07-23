// Jest config for the tracker JS unit tests (Layer 2 of the tracker test
// harness). Tests live in tests/js/ and exercise the ES-module tracker source
// under modules/base/src/. jsdom provides window/document/navigator so the
// Tracker and Event classes can be instantiated outside a browser.
module.exports = {
    testEnvironment: 'jsdom',
    roots: ['<rootDir>/tests/js'],
    testMatch: ['**/*.test.js'],
    // babel.config.js transforms the ESM source to CommonJS for node.
    transform: {
        '^.+\\.js$': 'babel-jest',
    },
};
