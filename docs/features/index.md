# Features Overview

This is the map of what Bouncer ships beyond the core "save through a
draft" loop.

## Admin UI

A self-contained admin interface at `/admin/bouncer/bouncer` — pending
queue, side-by-side and inline diffs, approve / reject with reasons,
filters by status / table / user, and clickable links to the source
records and proposers.

→ [Admin UI](./admin-ui)

## Approval Workflow

The internal flow: how a save becomes a draft, how the queue is filtered
on re-edits, the database schema, and the entity helpers that surface
staleness and merge state.

→ [Approval Workflow](./approval-workflow)

## 3-Way Merge

When a proposal becomes stale because the source record changed
independently, Bouncer auto-merges non-overlapping edits and surfaces
real conflicts for manual resolution. Includes recipes for custom merge
logic and using the `ThreeWayMerge` class directly.

→ [3-Way Merge](./three-way-merge)

## Advanced Patterns

Bypass callbacks (role-based, entity-based), conditional bouncer
attachment, custom programmatic approval flows, status-change
notifications, and dashboard widgets.

→ [Advanced Patterns](./advanced-patterns)

## AuditStash Integration

Pair Bouncer (logs the approval workflow) with
[cakephp-audit-stash](https://github.com/dereuromark/cakephp-audit-stash)
(logs the data changes the approval applies) for a complete audit trail.

→ [AuditStash Integration](./audit-stash-integration)

## Troubleshooting

The common "I expected this to be bounced and it wasn't" / "approval
failed silently" / "validation rejected my draft" symptoms with their
fixes.

→ [Troubleshooting](./troubleshooting)
