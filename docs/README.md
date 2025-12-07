# Bouncer Plugin Documentation

## Quick Start

### 1. Enable Bouncer in Your Table

Add the behavior to any table that should use approval workflow:

```php
class ArticlesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->addBehavior('Bouncer.Bouncer', [
            'userField' => 'user_id', // Field that identifies the user
        ]);
    }
}
```

### 2. Update Your Controller

Modify your controller to handle drafts and provide user context:

```php
public function edit($id = null)
{
    $article = $this->Articles->get($id);

    // Check for existing draft
    $userId = $this->Authentication->getIdentity()->getIdentifier();
    $draft = $this->Articles->getBehavior('Bouncer')->loadDraft($id, $userId);

    if ($draft) {
        $article = $this->Articles->patchEntity($article, $draft->getData());
        $this->Flash->info('You are editing your pending draft');
    }

    if ($this->request->is(['patch', 'post', 'put'])) {
        $article = $this->Articles->patchEntity($article, $this->request->getData());

        // Pass user ID to bouncer
        $this->Articles->save($article, ['bouncerUserId' => $userId]);

        if ($this->Articles->getBehavior('Bouncer')->wasBounced()) {
            $this->Flash->success('Your changes are pending approval');
            return $this->redirect(['action' => 'index']);
        }
    }

    $this->set(compact('article'));
}
```

### 3. Configure Admin Routes

In your \`config/routes.php\`:

```php
$routes->prefix('Admin', function (RouteBuilder $routes) {
    $routes->plugin('Bouncer', function (RouteBuilder $routes) {
        $routes->connect('/pending', ['controller' => 'Bouncer', 'action' => 'index']);
        $routes->fallbacks();
    });
});
```

### 4. Access Admin Interface

Navigate to \`/admin/bouncer/bouncer\` to review pending changes:
- Filter by status, table, or user
- View side-by-side diff of changes
- Approve or reject with optional reason/note

That's it! Your table now requires approval for all changes.

## Configuration Options

```php
$this->addBehavior('Bouncer.Bouncer', [
    // Field that contains user ID (required for tracking who made changes)
    'userField' => 'user_id',

    // Which actions require approval: 'add', 'edit', 'delete'
    'requireApproval' => ['add', 'edit', 'delete'],

    // User IDs that bypass bouncer (e.g., admin users)
    'exemptUsers' => [1, 2, 3],

    // Custom callback for bypass logic (more flexible than exemptUsers)
    'bypassCallback' => null,

    // Validate entity data when creating draft (recommended)
    'validateOnDraft' => true,

    // Automatically supersede other pending drafts for same record
    'autoSupersede' => true,
]);
```

## Advanced Usage

### Admin-Only Bypass

Allow admins to save directly without approval:

```php
// In controller
if ($this->Authentication->getIdentity()->isAdmin()) {
    $this->Articles->save($article, ['bypassBouncer' => true]);
} else {
    $this->Articles->save($article, ['bouncerUserId' => $userId]);
}
```

Or configure at behavior level:

```php
$this->addBehavior('Bouncer.Bouncer', [
    'exemptUsers' => [1, 2, 3], // Admin user IDs
]);
```

### Custom Bypass Logic with Callback

For more flexibility, use a callback instead of hardcoded user IDs. This allows integration with policies, roles, or any custom authorization logic:

```php
$this->addBehavior('Bouncer.Bouncer', [
    'bypassCallback' => function ($entity, $options, $table) {
        // Access identity from options
        $identity = $options['identity'] ?? null;

        // Use CakePHP Authorization plugin
        return $identity && $identity->can('bypassBouncer', $entity);
    },
]);
```

Role-based example:

```php
$this->addBehavior('Bouncer.Bouncer', [
    'bypassCallback' => function ($entity, $options, $table) {
        $userId = $options['bouncerUserId'] ?? $entity->get('user_id');

        // Load user and check role
        $usersTable = $table->fetchTable('Users');
        $user = $usersTable->get($userId);

        return in_array($user->role, ['admin', 'editor']);
    },
]);
```

Entity-based example (bypass for specific content types):

```php
$this->addBehavior('Bouncer.Bouncer', [
    'bypassCallback' => function ($entity, $options, $table) {
        // Skip approval for draft posts
        return $entity->status === 'draft';
    },
]);
```

The callback receives three parameters:
- \`$entity\`: The entity being saved/deleted
- \`$options\`: ArrayObject with save/delete options (includes \`bouncerUserId\`)
- \`$table\`: The table instance

**Note:** \`exemptUsers\` still works as a fallback for simple cases and backward compatibility.

### Programmatic Approval

Approve changes programmatically:

```php
$bouncerTable = $this->fetchTable('Bouncer.BouncerRecords');
$bouncerRecord = $bouncerTable->get($id);

$articlesTable = $this->fetchTable('Articles');
$articlesTable->addBehavior('Bouncer.Bouncer');

$entity = $articlesTable->getBehavior('Bouncer')->applyApprovedChanges($bouncerRecord);

if ($entity) {
    $bouncerTable->patchEntity($bouncerRecord, [
        'status' => 'approved',
        'reviewer_id' => $adminUserId,
        'reviewed' => new DateTime(),
    ]);
    $bouncerTable->save($bouncerRecord);
}
```

### Check for Pending Drafts

```php
$hasDraft = $this->Articles->getBehavior('Bouncer')->hasPendingDraft($articleId, $userId);

if ($hasDraft) {
    $this->Flash->info('You have pending changes for this record');
}
```

## Integration with AuditStash

Bouncer works beautifully with [cakephp-audit-stash](https://github.com/dereuromark/cakephp-audit-stash) to provide complete audit trail:

```php
// In BouncerRecordsTable
public function initialize(array $config): void
{
    parent::initialize($config);
    $this->addBehavior('Timestamp');
    $this->addBehavior('AuditStash.AuditLog'); // Track approval workflow
}

// In your application tables
public function initialize(array $config): void
{
    parent::initialize($config);
    $this->addBehavior('Bouncer.Bouncer');
    $this->addBehavior('AuditStash.AuditLog'); // Track actual changes
}
```

This creates two audit trails:
1. **Bouncer approval workflow** - Who proposed, when, approval/rejection
2. **Actual data changes** - When approved changes are applied to main table

## Customizing the Admin UI

### Linking Users and Records

The admin interface can display clickable links to users and source records. Configure in your `config/app.php`:

```php
'Bouncer' => [
    // Link to user profile/admin page
    'linkUser' => ['prefix' => 'Admin', 'controller' => 'Users', 'action' => 'view', '{user}'],

    // Link to source records (supports plugin models)
    'linkRecord' => function ($source, $primaryKey, $plugin, $tableName) {
        return [
            'plugin' => $plugin ?: false,
            'prefix' => 'Admin',
            'controller' => $tableName,
            'action' => 'view',
            $primaryKey,
        ];
    },
],
```

Available placeholders for `linkRecord`:
- `{source}`: Full source name (e.g., "Community.Stories")
- `{plugin}`: Plugin name or empty string (e.g., "Community")
- `{table}`: Table name without plugin (e.g., "Stories")
- `{primary_key}`: The record's primary key

For `linkUser`:
- `{user}`: The user ID

### User Display Names

To show user names instead of just IDs, populate the `user_display` and `reviewer_display` columns when creating bouncer records. You can do this by extending the behavior or in your application's save logic.

## How It Works

### Workflow Overview

1. **User creates/edits record**: Bouncer intercepts \`beforeSave()\` and creates a \`bouncer_record\` instead
2. **Draft stored**: Entity data serialized to JSON in \`bouncer_records\` table with status "pending"
3. **Re-edits update draft**: If user edits again, same bouncer record is updated (no duplicates)
4. **Admin reviews**: Via \`/admin/bouncer/bouncer\` interface, admin sees diff view
5. **On approval**:
   - Data applied to actual table (new record created or existing updated)
   - Bouncer record marked as "approved"
   - AuditStash logs the actual data change (if enabled)
6. **On rejection**: Bouncer record marked as "rejected" with reason

### Database Schema

The \`bouncer_records\` table stores:
- \`source\`: Model name (e.g., "Articles", "Community.Stories" for plugins)
- \`primary_key\`: Record ID (NULL for new records)
- \`user_id\`: Who proposed the change (foreign key)
- \`user_display\`: Optional display name for the user
- \`reviewer_id\`: Who approved/rejected (foreign key)
- \`reviewer_display\`: Optional display name for the reviewer
- \`status\`: pending/approved/rejected/superseded
- \`data\`: JSON serialized proposed changes
- \`original_data\`: JSON serialized original data (for edits)
- \`reason\`: Approval/rejection note
- Timestamps: `created`, `modified`, `reviewed`

### UUID Support

If your application uses UUIDs for primary keys or user IDs, you need to copy and adjust the migration on the app side.

1. Copy the migration from `vendor/dereuromark/cakephp-bouncer/config/Migrations/` to your app's `config/Migrations/` folder
2. Adjust the field types as needed:

```php
// For UUID primary keys in your source tables
->addColumn('primary_key', 'uuid', [
    'default' => null,
    'null' => true,
    'comment' => 'ID of record in source table, NULL for new records',
])

// For UUID user IDs
->addColumn('user_id', 'uuid', [
    'default' => null,
    'null' => false,
    'comment' => 'User who proposed the change',
])
->addColumn('reviewer_id', 'uuid', [
    'default' => null,
    'null' => true,
    'comment' => 'Admin who approved/rejected',
])
```

3. Run the migration from your app: `bin/cake migrations migrate`

**Note:** Do not run the plugin migration directly if you need UUID support - use your adjusted app migration instead.

## 3-Way Merge for Stale Proposals

When a proposal becomes "stale" (the source record has been modified since the proposal was created), Bouncer automatically performs a 3-way merge to preserve both sets of changes when possible.

### How It Works

1. **Staleness Detection**: Proposals store the original `modified` timestamp (`original_modified`). When approving, Bouncer compares this with the current record's `modified` timestamp.

2. **Automatic Merge**: If the source record has been modified, `applyApprovedChanges()` automatically:
   - Loads the current record state
   - Performs a 3-way merge between original, current, and proposed data
   - Applies the merged result

3. **Conflict Resolution**:
   - **Non-overlapping changes**: Both sets of changes are preserved (e.g., owner removes typo, contributor fixes spelling elsewhere)
   - **Overlapping changes**: Proposed value takes precedence (can be customized)

### Example Scenario

```
Original:  "Hello!!!! World"
Current:   "Hello World"      (owner removed "!!!!")
Proposed:  "Hello!!!! Universe" (contributor changed "World" to "Universe")
Merged:    "Hello Universe"   (both changes preserved!)
```

### Options for `applyApprovedChanges()`

```php
$bouncer->applyApprovedChanges($bouncerRecord, [
    // Disable auto-merge to use proposed data as-is (default: true)
    'autoMerge' => false,

    // Custom fields to skip during merge
    'skipFields' => ['id', 'created', 'modified', 'internal_field'],
]);
```

### Pre-Merging Data

For custom merge logic or UI-driven conflict resolution, you can pre-merge data:

```php
// Build your own merged data
$mergedData = ['title' => 'Custom merged title', ...];

// Set it on the bouncer record
$bouncerRecord->setMergedData($mergedData);

// Apply - will use your merged data instead of auto-merging
$bouncer->applyApprovedChanges($bouncerRecord);
```

### Using BouncerRecord Helper Methods

The `BouncerRecord` entity provides convenient methods for staleness detection and merging:

```php
// Check if a draft is stale (source record was modified after draft creation)
if ($bouncerRecord->isStale($currentEntity)) {
    // Handle stale draft
}

// Build merge result (returns null if not stale)
$mergeResult = $bouncerRecord->buildMergeResult($currentEntity);
if ($mergeResult) {
    // $mergeResult contains: merged, conflicts, autoMerged, hasConflicts
    if ($mergeResult['hasConflicts']) {
        // Handle conflicts
    } else {
        // Use $mergeResult['merged'] for the auto-merged data
    }
}
```

### Using ThreeWayMerge Directly

For advanced use cases or custom merge logic:

```php
use Bouncer\Lib\ThreeWayMerge;

$merger = new ThreeWayMerge();

// Merge string values
$result = $merger->mergeStrings($original, $current, $proposed);
if ($result['status'] === ThreeWayMerge::MERGED) {
    echo $result['result']; // Successfully merged
} else {
    // ThreeWayMerge::CONFLICT - manual resolution needed
}

// Merge arrays of entity data
$result = $merger->mergeArrays($originalData, $currentData, $proposedData);
// Returns: merged, conflicts, autoMerged, hasConflicts
```
