# Bouncer Plugin For CakePHP

[![Build Status](https://github.com/dereuromark/cakephp-bouncer/actions/workflows/ci.yml/badge.svg)](https://github.com/dereuromark/cakephp-bouncer/actions/workflows/ci.yml)
[![Coverage Status](https://img.shields.io/codecov/c/github/dereuromark/cakephp-bouncer/master.svg?style=flat-square)](https://codecov.io/github/dereuromark/cakephp-bouncer)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg?style=flat)](https://phpstan.org/)
[![Latest Stable Version](https://poser.pugx.org/dereuromark/cakephp-bouncer/v/stable.svg)](https://packagist.org/packages/dereuromark/cakephp-bouncer)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%208.2-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)
[![Total Downloads](https://poser.pugx.org/dereuromark/cakephp-bouncer/d/total.svg)](https://packagist.org/packages/dereuromark/cakephp-bouncer)
[![Coding Standards](https://img.shields.io/badge/cs-PhpCollective-purple.svg?style=flat-square)](https://github.com/php-collective/code-sniffer)

This plugin implements an approval workflow for CakePHP applications. Users propose changes (create or edit records), and admins/moderators can review, approve, or reject those changes before they are published to the actual database tables.

Perfect for:
- Content management systems requiring editorial approval
- User-generated content that needs moderation
- Data entry systems with quality control
- Multi-stage approval workflows

**Note:** Revert functionality is intentionally out of scope for this plugin. For reverting changes to previous states, use the [cakephp-audit-stash](https://github.com/dereuromark/cakephp-audit-stash) plugin which provides comprehensive audit logging and revert capabilities. Bouncer focuses solely on the approval workflow for proposed changes.

## Features

- **Seamless Integration**: Add approval workflow to any table with a single behavior
- **Draft Management**: Users automatically edit their existing drafts instead of creating duplicates
- **Admin Interface**: Built-in UI for reviewing and approving/rejecting changes with diff view
- **Flexible Configuration**: Configure which actions require approval, use custom bypass callbacks
- **Transaction Safety**: Atomic approval process with rollback on errors
- **AuditStash Integration**: Works seamlessly with cakephp-audit-stash for complete audit trail

## Installation

Install via [composer](https://getcomposer.org):

```bash
composer require dereuromark/cakephp-bouncer
bin/cake plugin load Bouncer
```

Run the migrations to create the \`bouncer_records\` table:

```bash
bin/cake migrations migrate -p Bouncer
```

## Documentation

See [docs/README.md](docs/README.md) for detailed documentation including:
- Quick start guide
- Configuration options
- Advanced usage (bypass callbacks, programmatic approval)
- AuditStash integration
- How it works

## Demo

See the plugin in action: [https://sandbox.dereuromark.de/sandbox/bouncer-examples](https://sandbox.dereuromark.de/sandbox/bouncer-examples)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for details on how to contribute to this project.
