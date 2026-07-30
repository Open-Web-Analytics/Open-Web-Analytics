# Open Web Analytics Server

Open Web Analytics is an open source alternative to commercial web analytics tools such as Google Analytics. This software allows you to stay in control of the data you collect about the user of your websites or applications.

This repository installs the OWA Server and Javascript tracking client which can easily be added to web pages. 

- To add OWA tracking to a WordPress based website install the [OWA integration plugin](https://wordpress.org/plugins/open-web-analytics/) or see [this repository](https://github.com/Open-Web-Analytics/owa-wordpress-plugin).
- To add OWA tracking to any PHP application use the [OWA PHP SDK](https://github.com/Open-Web-Analytics/owa-php-sdk)

## Features

- Track visitors, pageviews, e-commerce transactions, and configurable actions
- Track unlimited number of websites using a single instance of OWA Server
- First party Javascript tracker client
- Reporting Dashboard/Portral
- View and customize all reports
- Generate Heatmaps
- Generate "Domstream" session recordings
- Geolocation of visitors
- REST API for administration and data access
- Multi user reporting interface
- Extensible framework via custom modules

## Requirements and Installation

See the [technical requirements](https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki/Technical-Requirements) before you install OWA Server. A step by step [installation](https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki/Installation) guide will walk you through how to install OWA.

## Documentation
See the wiki for documentation about the OWA Server and the Javascript Tracker client.

Upgrading, or maintaining a third-party module, local template override, or custom theme? See [UPGRADING.md](UPGRADING.md) for the interfaces that are deprecated but still supported, and what replaces each one.

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
