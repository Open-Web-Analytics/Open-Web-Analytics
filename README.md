# Open Web Analytics Server

Open Web Analytics is an open source alternative to commercial web analytics tools. This software allows you to stay in control of the data you collect about the user of your websites or applications.

This repository installs the OWA Server and Javascript tracking client which can easily be added to web pages. 

- To add OWA tracking to a WordPress based website install the [OWA integration plugin](https://wordpress.org/plugins/open-web-analytics/) or see [this repository](https://github.com/Open-Web-Analytics/owa-wordpress-plugin).
- To add OWA tracking to any PHP application use the [OWA PHP SDK](https://github.com/Open-Web-Analytics/owa-php-sdk)

## Features

- Events: page views, clicks, scrolls, downloads, outbound links, site search,
  form interactions, errors, e-commerce and your own custom events
- Properties and Observation Profiles: report on a site or app as a whole, or on
  one of its Profiles
- Goal events with a count mode and a value, and funnels whose steps are events
- Custom reports, custom dimensions and event properties
- Heatmaps and session recordings ("domstreams")
- Spam and bot scoring at ingest
- Any number of sites on one OWA Server, with a first-party JavaScript tracker
- A REST API for administration and data access, with application passwords
- Multi-user reporting, and an extensible framework of modules

## Requirements and Installation

OWA 2.0 needs PHP 8.2 or later, MySQL 8.0.21 or later or MariaDB 10.11 or later,
and a cron entry for its scheduler: without it, events are recorded but reports
stay empty. See the
[technical requirements](https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki/v2-Technical-Requirements)
before you install, and the
[installation](https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki/Installation)
guide for the steps.

## Upgrading from 1.x

2.0 upgrades from 1.14 only, so upgrade an older 1.x installation to the latest
1.14 release first. The upgrade includes a one-time migration of your 1.x history,
run from the command line. See
[Upgrading from 1.x](https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki/Upgrading-from-1.x)
and [UPGRADING.md](UPGRADING.md).

## Documentation

The wiki's [2.0 documentation](https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki/v2-Home)
covers the server, the reports and the JavaScript tracker.
[Changes from 1.x](https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki/v2-Changes-from-1.x)
lists what is different.

Maintaining a third-party module, local template override, or custom theme? See
[UPGRADING.md](UPGRADING.md) for what 2.0 removed and what replaces each one.

## Tracker cookie options

The tracker's cookies have fixed lifetimes by default: 364 days for the visitor id
(`owa_v`) and 364 for the session store (`owa_s_<site id>`). A
year-long identifier is longer than some sites want or can justify, so the snippet
can ask for something shorter.

These are ordinary tracker options, set from the snippet the same way
`cookie_domain` and the campaign keys are:

```js
owa_cmds.push(['setOption', 'stateStoreExpirations', {"v": 90, "s": 7}]);
```

`stateStoreExpirations` is keyed by store: `v` for the visitor id, `s` for the
session store. Stores you leave out keep their defaults. Values are whole days,
zero or more; anything else is ignored rather than guessed at.

**0 days makes that cookie a *session* cookie**: no expiry date, discarded when
the browser closes, and a returning visitor counted as new.
`{"v": 0, "s": 0}` does it for both.

`setOption('cookiePersistence', false)`, from 1.14.0, does the same for every
cookie the tracker writes and still works, but is **deprecated in 2.0** in favour
of 0 days; see [UPGRADING.md](UPGRADING.md).

Put them before `trackPageView`, as in the snippet above. The command queue is
drained in push order, and `trackPageView` is what writes the first cookie.

Each cookie is rewritten on every page view, so shortening a lifetime gives a
**rolling window**: a visitor's id expires that many days after their *last*
visit, not after their first, and a visitor who keeps coming back is never
forgotten. Shortening one does not delete anything already collected: it limits
how far back returning-visitor and days-since-first-visit reporting can reach for
people who stop visiting.

Shortening the visitor cookie is the usual reason to touch these. Several consent
exemptions for analytics, the Dutch Telecommunicatiewet art. 11.7a(3) among them,
turn on the tracking having a small privacy impact, and a year-long identifier is
hard to argue as small.

Browsers impose their own ceiling that no value here can exceed: Chrome caps
cookie expiry at 400 days, and Safari caps script-written cookies considerably
lower.

## Issues & Support

Please read the [troubleshooting](https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki/Troubleshooting) guide before filing any issue or bug reports. Issue tickets without the necessary debug info will be closed automatically.

## Development

To contribute to OWA Server:

1. Clone the repository.
2. Install [Composer](https://getcomposer.org/) (PHP dependencies) and [Node.js/npm](https://nodejs.org/) (JavaScript build).
3. Install dependencies and build the front-end assets:

   ```bash
   composer install
   npm install && npm run build
   ```

   `vendor/` and the built `public/` asset tree (including the JS tracker) are not tracked in git — you must build them after cloning.

See the [Development](https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki/Development) and [Testing](https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki/Testing) wiki pages for the full build process and test suites.


## Donate to this project

Open Web Analytics is free.  However, we ask that you donate to the project if you need support. Your donation helps fund the development of this project.

[Donate to the project here](http://paypal.me/openwebanalytics).


## Copyright and License

This project is licensed under the [GNU GPL](http://www.gnu.org/licenses/old-licenses/gpl-2.0.html), version 2 or later.

&copy; [Peter Adams](http://peteradams.org).
