---
layout: home

hero:
  name: cakephp-bouncer
  text: Approval Workflow for CakePHP
  tagline: Users propose changes — admins review, approve, or reject before they hit the live tables. Diff viewer, 3-way merge, and admin UI included.
  image:
    src: /logo.svg
    alt: cakephp-bouncer
  actions:
    - theme: brand
      text: Get Started
      link: /guide/
    - theme: alt
      text: Features
      link: /features/
    - theme: alt
      text: Live Demo
      link: https://sandbox.dereuromark.de/sandbox/bouncer-examples
    - theme: alt
      text: View on GitHub
      link: https://github.com/dereuromark/cakephp-bouncer

features:
  - icon: 🛂
    title: Drop-In Approval Workflow
    details: One behavior on a Table turns saves into pending drafts. The original record stays untouched until an admin approves the proposal.
  - icon: 🖥️
    title: Admin Diff Viewer
    details: Side-by-side and inline diffs (word-level when jfcherng/php-diff is installed), status badges, filters, and one-click approve/reject with reasons.
  - icon: 🔀
    title: 3-Way Merge
    details: Stale proposals merge automatically when the source record changed independently. Non-overlapping edits combine; conflicts surface for manual resolution.
  - icon: 📝
    title: Draft-Safe Re-Edits
    details: Users editing the same record see and update their own pending draft instead of stacking duplicates. Auto-supersede keeps the queue clean.
  - icon: 🎛️
    title: Flexible Bypass
    details: Exempt user lists, custom bypass callbacks, or per-save `bypassBouncer` flags — integrate cleanly with policies, roles, or admin tooling.
  - icon: 🔗
    title: Pairs With AuditStash
    details: Bouncer logs the approval workflow; AuditStash logs the data changes that the approval applies. Together — full audit trail.
---
