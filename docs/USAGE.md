# Usage Guide

This guide covers common usage patterns and scenarios for the Bouncer plugin.

## Table of Contents

- [Basic Setup](#basic-setup)
- [Controller Integration](#controller-integration)
- [Configuration Options](#configuration-options)
- [Advanced Patterns](#advanced-patterns)
- [Troubleshooting](#troubleshooting)

## Basic Setup

### Enable Bouncer on a Table

```php
// src/Model/Table/ArticlesTable.php
class ArticlesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->addBehavior('Bouncer.Bouncer', [
            'userField' => 'user_id',
        ]);
    }
}
```

### Run Migrations

```bash
bin/cake migrations migrate -p Bouncer
```

## Controller Integration

### Standard CRUD Pattern

```php
// src/Controller/ArticlesController.php
public function add()
{
    $article = $this->Articles->newEmptyEntity();

    if ($this->request->is('post')) {
        $article = $this->Articles->patchEntity($article, $this->request->getData());

        // Get current user ID
        $userId = $this->Authentication->getIdentity()->getIdentifier();

        // Save with user context
        $this->Articles->save($article, ['bouncerUserId' => $userId]);

        // Check if save was bounced
        if ($this->Articles->getBehavior('Bouncer')->wasBounced()) {
            $this->Flash->success('Your submission is pending approval');
            return $this->redirect(['action' => 'index']);
        }

        // Handle validation errors
        if ($article->hasErrors()) {
            $this->Flash->error('Please correct the errors below');
        }
    }

    $this->set(compact('article'));
}

public function edit($id = null)
{
    $article = $this->Articles->get($id);
    $userId = $this->Authentication->getIdentity()->getIdentifier();

    // Load existing draft if present
    $draft = $this->Articles->getBehavior('Bouncer')->loadDraft($id, $userId);
    if ($draft) {
        // Overlay draft data on published record
        $article = $this->Articles->patchEntity($article, $draft->getData());
        $this->set('draftId', $draft->id);
        $this->Flash->info('You are editing your pending draft');
    }

    if ($this->request->is(['patch', 'post', 'put'])) {
        $article = $this->Articles->patchEntity($article, $this->request->getData());

        $this->Articles->save($article, ['bouncerUserId' => $userId]);

        if ($this->Articles->getBehavior('Bouncer')->wasBounced()) {
            $this->Flash->success('Your changes are pending approval');
            return $this->redirect(['action' => 'index']);
        }
    }

    $this->set(compact('article'));
}
```

### Alternative: Use Entity Field

If your entity always has a `user_id` field:

```php
$this->addBehavior('Bouncer.Bouncer', [
    'userField' => 'user_id',
]);

// In controller
$article->user_id = $this->Authentication->getIdentity()->getIdentifier();
$this->Articles->save($article); // Bouncer reads user_id from entity
```

## Configuration Options

### Require Approval for Specific Actions

```php
// Only new records require approval
$this->addBehavior('Bouncer.Bouncer', [
    'requireApproval' => ['add'],
]);

// Only edits require approval
$this->addBehavior('Bouncer.Bouncer', [
    'requireApproval' => ['edit'],
]);

// Both (default)
$this->addBehavior('Bouncer.Bouncer', [
    'requireApproval' => ['add', 'edit'],
]);
```

### Exempt Specific Users

```php
$this->addBehavior('Bouncer.Bouncer', [
    'exemptUsers' => [1, 2, 3], // Admin user IDs
]);
```

### Validation Options

```php
// Validate on draft creation (default: true)
$this->addBehavior('Bouncer.Bouncer', [
    'validateOnDraft' => true,
]);

// Allow invalid drafts (validate only on approval)
$this->addBehavior('Bouncer.Bouncer', [
    'validateOnDraft' => false,
]);
```

### Supersede Behavior

```php
// Automatically mark other pending drafts as superseded (default: true)
$this->addBehavior('Bouncer.Bouncer', [
    'autoSupersede' => true,
]);

// Allow multiple pending drafts
$this->addBehavior('Bouncer.Bouncer', [
    'autoSupersede' => false,
]);
```

## Advanced Patterns

### Conditional Bouncer

Enable bouncer only for certain conditions:

```php
// In AppController
public function beforeFilter(EventInterface $event)
{
    parent::beforeFilter($event);

    $identity = $this->Authentication->getIdentity();

    // Non-admins use bouncer
    if (!$identity->isAdmin()) {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'userField' => 'user_id',
        ]);
    }
}
```

### Bypass Bouncer for Specific Save

```php
// Admin direct save
$this->Articles->save($article, ['bypassBouncer' => true]);
```

### Custom Approval Logic

```php
// src/Controller/Admin/ApprovalsController.php
public function customApprove($id)
{
    $bouncerTable = $this->fetchTable('Bouncer.BouncerRecords');
    $bouncerRecord = $bouncerTable->get($id);

    // Custom validation
    if (!$this->validateProposal($bouncerRecord)) {
        $this->Flash->error('Proposal does not meet requirements');
        return $this->redirect(['action' => 'view', $id]);
    }

    // Apply changes
    $sourceTable = $this->fetchTable($bouncerRecord->source);
    $sourceTable->addBehavior('Bouncer.Bouncer');

    $connection = $bouncerTable->getConnection();
    $connection->transactional(function () use ($bouncerRecord, $sourceTable, $bouncerTable) {
        $entity = $sourceTable->getBehavior('Bouncer')->applyApprovedChanges($bouncerRecord);

        $bouncerTable->patchEntity($bouncerRecord, [
            'status' => 'approved',
            'reviewer_id' => $this->Authentication->getIdentity()->getIdentifier(),
            'reviewed' => new DateTime(),
            'primary_key' => $entity->id,
        ]);

        $bouncerTable->save($bouncerRecord);
    });

    $this->Flash->success('Approved and published');
    return $this->redirect(['action' => 'index']);
}
```

### Notification on Status Change

```php
// In BouncerRecordsTable
public function afterSave(EventInterface $event, EntityInterface $entity, ArrayObject $options)
{
    if ($entity->isDirty('status')) {
        if ($entity->status === 'approved') {
            $this->notifyUser($entity->user_id, 'Your changes were approved!');
        } elseif ($entity->status === 'rejected') {
            $this->notifyUser($entity->user_id, 'Your changes were rejected: ' . $entity->reason);
        }
    }
}

protected function notifyUser($userId, $message)
{
    // Send email, create notification, etc.
}
```

### Dashboard Widget

```php
// Show pending count in dashboard
public function dashboard()
{
    $bouncerTable = $this->fetchTable('Bouncer.BouncerRecords');

    $pendingCount = $bouncerTable->find()
        ->where(['status' => 'pending'])
        ->count();

    $this->set(compact('pendingCount'));
}
```

## Troubleshooting

### Changes Saving Directly Instead of Creating Drafts

**Problem**: Records save normally instead of being bounced.

**Solutions**:
1. Verify behavior is added: `$this->hasBehavior('Bouncer')`
2. Check `requireApproval` includes the action ('add' or 'edit')
3. Ensure user ID is being passed via `bouncerUserId` option or entity field
4. Check if user is in `exemptUsers` list
5. Verify `bypassBouncer` option is not set

### Drafts Not Loading on Re-edit

**Problem**: User edits same record but doesn't see their pending draft.

**Solutions**:
1. Call `loadDraft()` in controller before rendering form
2. Ensure correct user ID is passed
3. Check draft status is 'pending'

### Validation Errors

**Problem**: Valid data is rejected when creating draft.

**Solutions**:
1. Check table validation rules
2. Set `validateOnDraft => false` to validate only on approval
3. Verify all required fields are present in draft data

### Approval Fails

**Problem**: Clicking approve doesn't apply changes.

**Solutions**:
1. Check error logs for exceptions
2. Verify source table still exists and is accessible
3. For edits, ensure original record still exists
4. Check database constraints (unique keys, foreign keys)
5. Review validation rules in source table

### Multiple Drafts for Same Record

**Problem**: Multiple pending drafts exist for one record.

**Solutions**:
- Enable `autoSupersede => true` (default)
- Manually supersede: `$bouncerTable->supersedeOthers($source, $primaryKey, $keepId)`

## Best Practices

1. **Always load drafts** in edit actions to prevent conflicts
2. **Provide user feedback** when changes are bounced vs saved
3. **Validate on draft** (default) to catch errors early
4. **Use transactions** when programmatically approving
5. **Set up notifications** to alert admins of pending approvals
6. **Integrate AuditStash** for complete audit trail
7. **Test approval workflow** before deploying to production
8. **Document exemptions** clearly in code comments
