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
- \`source\`: Table name (e.g., "Articles")
- \`primary_key\`: Record ID (NULL for new records)
- \`user_id\`: Who proposed the change
- \`reviewer_id\`: Who approved/rejected
- \`status\`: pending/approved/rejected/superseded
- \`data\`: JSON serialized proposed changes
- \`original_data\`: JSON serialized original data (for edits)
- \`reason\`: Approval/rejection note
- Timestamps: \`created\`, \`modified\`, \`reviewed\`
