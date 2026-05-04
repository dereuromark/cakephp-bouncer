# Bouncer Plugin For CakePHP

[![Build Status](https://github.com/dereuromark/cakephp-bouncer/actions/workflows/ci.yml/badge.svg)](https://github.com/dereuromark/cakephp-bouncer/actions/workflows/ci.yml)
[![Coverage Status](https://img.shields.io/codecov/c/github/dereuromark/cakephp-bouncer/master.svg?style=flat-square)](https://codecov.io/github/dereuromark/cakephp-bouncer)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg?style=flat)](https://phpstan.org/)
[![Latest Stable Version](https://poser.pugx.org/dereuromark/cakephp-bouncer/v/stable.svg)](https://packagist.org/packages/dereuromark/cakephp-bouncer)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%208.2-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)
[![Total Downloads](https://poser.pugx.org/dereuromark/cakephp-bouncer/d/total.svg)](https://packagist.org/packages/dereuromark/cakephp-bouncer)
[![Coding Standards](https://img.shields.io/badge/cs-PhpCollective-purple.svg?style=flat-square)](https://github.com/php-collective/code-sniffer)

This branch is for **CakePHP 5.1+**. See [version map](https://github.com/dereuromark/cakephp-bouncer/wiki#cakephp-version-map) for details.

Approval workflow for CakePHP: users propose changes, admins/moderators review and approve or reject before the changes hit the live database. Built for content-management systems, user-generated content moderation, data-entry quality control, and multi-stage editorial workflows.

## Features

- **Drop-in approval workflow** — single behavior on a Table turns saves into pending drafts; original record stays untouched until approved.
- **Admin diff viewer** — built-in UI for the queue with side-by-side and inline diffs (word-level via `jfcherng/php-diff`), filters, status badges, and one-click approve / reject with reasons.
- **3-way merge** — proposals that became stale because the source record changed independently auto-merge non-overlapping edits; real conflicts surface for manual resolution.
- **Draft-safe re-edits** — users editing the same record see and update their own pending draft instead of stacking duplicates; auto-supersede keeps the queue focused.
- **Flexible bypass** — exempt user lists, custom bypass callbacks, or per-save `bypassBouncer` flag — integrate with policies, roles, or admin tooling.
- **Pairs with AuditStash** — Bouncer logs the approval workflow; [cakephp-audit-stash](https://github.com/dereuromark/cakephp-audit-stash) logs the data changes the approvals apply.
- **Transaction-safe** — atomic apply on approval; failures roll back cleanly and leave the queue intact.

## Installation

```bash
composer require dereuromark/cakephp-bouncer
bin/cake plugin load Bouncer
bin/cake migrations migrate -p Bouncer
```

Then add the behavior to any Table that should require approval — see the [Getting Started guide](https://dereuromark.github.io/cakephp-bouncer/guide/).

> **Reverts are out of scope.** Bouncer is the gate *before* changes ship. To roll a record back *after* it shipped, use AuditStash's [Revert / Restore feature](https://dereuromark.github.io/cakephp-audit-stash/features/revert).

## Documentation

Full docs: **<https://dereuromark.github.io/cakephp-bouncer/>**

- [Getting Started](https://dereuromark.github.io/cakephp-bouncer/guide/) — installation, behavior setup, controller wiring
- [Configuration](https://dereuromark.github.io/cakephp-bouncer/guide/configuration) — behavior options and `Bouncer.*` app config
- [Usage](https://dereuromark.github.io/cakephp-bouncer/guide/usage) — controller patterns, draft re-edit, programmatic approval
- [View Helper](https://dereuromark.github.io/cakephp-bouncer/guide/view-helper) — render proposals in your own templates
- [Features overview](https://dereuromark.github.io/cakephp-bouncer/features/) — admin UI, approval workflow, 3-way merge, advanced patterns, AuditStash integration

## Demo

<https://sandbox.dereuromark.de/sandbox/bouncer-examples>

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).
