# 3-Way Merge

Proposals can sit in the queue for a while. In that time, the source
record may change — a typo gets fixed, a tag gets added, a status flips.
By the time the admin reviews the original proposal, applying it
verbatim would silently overwrite those independent edits.

Bouncer detects this and merges automatically when it can. When it
can't, it surfaces conflicts so the admin can choose.

## How it works

Each draft snapshots the source record's `modified` timestamp into
`original_modified` at the moment the draft is created. On approval,
`applyApprovedChanges()` compares that to the current record's `modified`:

- **Not stale**: the source row hasn't moved → apply the draft as-is
- **Stale**: the source row moved → 3-way merge between
  - **original** (what the source row looked like when the draft was made)
  - **current** (what the source row looks like now)
  - **proposed** (the draft's data)

## Conflict resolution

| Situation | Result |
|---|---|
| Owner removed `"!!!!"` from `title`; contributor changed `"World"` → `"Universe"` | both kept — `"Hello Universe"` |
| Owner changed `body` to "v2"; contributor changed `body` to "v3" | conflict — proposed value wins by default |
| Owner added a `tags` entry; contributor didn't touch `tags` | owner's edit kept |
| Contributor added a `tags` entry; owner didn't touch `tags` | contributor's edit kept |

> The default resolution rule is **proposed wins on conflict** — that
> matches "I'm an editor approving someone else's submission, take their
> word over the parallel edit." If you'd prefer "current wins" or a
> manual gate, see [Pre-merging data](#pre-merging-data) below.

## Worked example

```
Original:  "Hello!!!! World"
Current:   "Hello World"           ← owner removed "!!!!"
Proposed:  "Hello!!!! Universe"    ← contributor changed "World" → "Universe"

Merged:    "Hello Universe"        ← both edits preserved
```

That's the auto-merge happy path. On `applyApprovedChanges()` the source
table receives the merged payload, not the proposed one.

## `applyApprovedChanges()` options

```php
$bouncer->applyApprovedChanges($bouncerRecord, [
    // Disable auto-merge entirely — apply proposed data verbatim, even
    // if the source row has moved since the draft was made.
    'autoMerge' => false,

    // Fields to leave alone during merge. Useful for fields that are
    // either machine-managed (timestamps, hash columns) or that should
    // never be reverted via approval (status, locked flags).
    'skipFields' => ['id', 'created', 'modified', 'internal_field'],
]);
```

`autoMerge => true` is the default. Pass `false` when you trust your
admin to have already eyeballed the diff and want a deterministic apply
— e.g., automated approval tooling that's already running its own merge.

## Pre-merging data

For UI-driven conflict resolution, hand-merging, or custom logic, set the
merged payload on the bouncer record before calling
`applyApprovedChanges()`:

```php
$mergedData = ['title' => 'Editor-resolved title', /* ... */];

$bouncerRecord->setMergedData($mergedData);
$bouncer->applyApprovedChanges($bouncerRecord);
// → uses $mergedData verbatim, no auto-merge runs
```

This is how a "review conflicts" screen would integrate: render the diff,
let the admin tick which side wins per field, build `$mergedData`, set
it, approve.

## Inspecting the merge before applying

The entity exposes the merge result without committing:

```php
if ($bouncerRecord->isStale($currentSourceEntity)) {
    $result = $bouncerRecord->buildMergeResult($currentSourceEntity);

    if ($result['hasConflicts']) {
        // Render a conflict-resolution UI; populate $mergedData from
        // the user's choices; call setMergedData() before approving.
        return $this->render('conflict_resolution', [
            'result' => $result,
        ]);
    }

    // No conflicts — auto-merge already produced a usable payload.
    // applyApprovedChanges() will reproduce the same result.
}
```

`$result` shape:

| Key | Type | Meaning |
|---|---|---|
| `merged` | array | the auto-merged payload that would be applied |
| `conflicts` | array | per-field `{original, current, proposed}` for fields that couldn't be merged |
| `autoMerged` | bool | true if every field merged cleanly |
| `hasConflicts` | bool | inverse of `autoMerged` |

## Using `ThreeWayMerge` directly

For non-bouncer use cases — diffing arbitrary records, user-side
proposal flows that don't go through the behavior — the merge engine is
usable on its own:

```php
use Bouncer\Lib\ThreeWayMerge;

$merger = new ThreeWayMerge();

// Strings: returns ['status' => MERGED|CONFLICT, 'result' => string]
$result = $merger->mergeStrings($original, $current, $proposed);
if ($result['status'] === ThreeWayMerge::MERGED) {
    echo $result['result'];
} else {
    // ThreeWayMerge::CONFLICT — the result key holds a marker-style diff
    // for manual resolution
}

// Entity-shaped arrays: returns the same shape as buildMergeResult()
$result = $merger->mergeArrays($originalData, $currentData, $proposedData);
// ['merged' => [...], 'conflicts' => [...], 'autoMerged' => bool, 'hasConflicts' => bool]
```

The class lives at `Bouncer\Lib\ThreeWayMerge` (`src/Lib/ThreeWayMerge.php`)
and has no dependencies on the rest of the plugin — drop it into a
non-bouncer project if you just need the merge logic.
