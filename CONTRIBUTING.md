# How to contribute

Thank you for helping. Bug fixes, tests and documentation are as welcome as
features.

## Branches

`v2` is where 2.0 development happens, and new work is based on it. `master` is
the 1.x line, which takes fixes only.

## Setting up

See the README's Development section: `composer install`, then
`npm install && npm run build`. `vendor/` and the built `public/` tree are not in
git.

## Testing

Every change comes with tests, and updates the existing tests it affects.

- PHP: `vendor/bin/phpunit`. Tests that need a database skip without one.
- Tracker and reporting JavaScript: `npx jest`.
- End to end: `npm run test:e2e:selfhost`, which installs into a scratch database
  and serves it with PHP's built-in server. Find elements by role or label
  first (`getByRole`, `getByLabel`), then by `data-ui="part:name"`
  (`ui(page, 'kpi:visits')` in `tests/e2e/fixtures.js`); never by a styling class.

The wiki's [Testing](https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki/Testing)
page has the details.

## Submitting changes

Open a pull request against `v2` with a clear description of what changed and
why. Keep each pull request to one change, and each commit to one step of it.

Write commit messages as a short summary line, a blank line, then a paragraph
saying what changed and its effect:

    A brief summary of the commit

    A paragraph describing what changed and its impact.

## Coding conventions

Read the code around a change and follow it. Comments say why, not what.
Strings shown to people go through the translation helpers.

## Security issues

Do not open a public issue or pull request for a vulnerability; see
[SECURITY.md](SECURITY.md).
