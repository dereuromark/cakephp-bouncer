<?php

declare(strict_types=1);

namespace Bouncer\Test\TestCase\Model\Behavior;

use Bouncer\Model\Behavior\BouncerBehavior;
use Bouncer\Model\Entity\BouncerRecord;
use Cake\Database\Connection;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;
use Throwable;

/**
 * Bouncer\Model\Behavior\BouncerBehavior Test Case
 */
class BouncerBehaviorTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'plugin.Bouncer.BouncerRecords',
        'plugin.Bouncer.Articles',
    ];

    /**
     * @var \TestApp\Model\Table\ArticlesTable
     */
    protected $Articles;

    /**
     * @var \Bouncer\Model\Table\BouncerRecordsTable
     */
    protected $BouncerRecords;

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->Articles = $this->fetchTable('TestApp.Articles');
        $this->BouncerRecords = $this->fetchTable('Bouncer.BouncerRecords');
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->Articles, $this->BouncerRecords);

        parent::tearDown();
    }

    /**
     * Test that adding behavior works
     */
    public function testInitialize(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer');
        $this->assertTrue($this->Articles->hasBehavior('Bouncer'));
    }

    /**
     * Test that new record creates bouncer record instead of saving
     */
    public function testBeforeSaveNewRecord(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'userField' => 'user_id',
        ]);

        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);

        $result = $this->Articles->save($article, ['bouncerUserId' => 1]);

        // Save should return false (bounced)
        $this->assertFalse($result);

        // Should be marked as bounced
        $this->assertTrue($this->Articles->getBehavior('Bouncer')->wasBounced());

        // Bouncer record should be created
        $bouncerRecord = $this->BouncerRecords->find()->first();
        $this->assertNotNull($bouncerRecord);
        $this->assertEquals('TestApp.Articles', $bouncerRecord->source);
        $this->assertNull($bouncerRecord->primary_key);
        $this->assertEquals('pending', $bouncerRecord->status);
        $this->assertEquals(1, $bouncerRecord->user_id);

        // Original table should not have record
        $count = $this->Articles->find()->count();
        $this->assertEquals(0, $count);
    }

    /**
     * Test that editing record creates bouncer record
     */
    public function testBeforeSaveEditRecord(): void
    {
        // Create an article first
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        // Now add bouncer behavior
        $this->Articles->addBehavior('Bouncer.Bouncer');

        // Edit the article
        $article = $this->Articles->get($articleId);
        $article->title = 'Updated Title';
        $article->body = 'Updated body';

        $result = $this->Articles->save($article, ['bouncerUserId' => 2]);

        // Save should be bounced
        $this->assertFalse($result);

        // Bouncer record should be created
        $bouncerRecord = $this->BouncerRecords->find()->first();
        $this->assertNotNull($bouncerRecord);
        $this->assertEquals('TestApp.Articles', $bouncerRecord->source);
        $this->assertEquals($articleId, $bouncerRecord->primary_key);
        $this->assertEquals('pending', $bouncerRecord->status);
        $this->assertEquals(2, $bouncerRecord->user_id);

        // Original should be unchanged
        $article = $this->Articles->get($articleId);
        $this->assertEquals('Original Title', $article->title);
    }

    /**
     * Test bypass bouncer option
     */
    public function testBypassBouncer(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer');

        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);

        $result = $this->Articles->save($article, ['bypassBouncer' => true]);

        // Should save normally
        $this->assertNotFalse($result);
        $this->assertFalse($this->Articles->getBehavior('Bouncer')->wasBounced());

        // No bouncer record should be created
        $count = $this->BouncerRecords->find()->count();
        $this->assertEquals(0, $count);

        // Article should exist
        $count = $this->Articles->find()->count();
        $this->assertEquals(1, $count);
    }

    /**
     * Test exempt users
     */
    public function testExemptUsers(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'exemptUsers' => [1, 2],
        ]);

        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);

        $result = $this->Articles->save($article, ['bouncerUserId' => 1]);

        // Should save normally (user is exempt)
        $this->assertNotFalse($result);
    }

    /**
     * Test require approval configuration
     */
    public function testRequireApprovalOnlyAdd(): void
    {
        // Create article first (before adding behavior to avoid transaction issues)
        $article = $this->Articles->newEntity([
            'title' => 'Test',
            'body' => 'Test',
            'user_id' => 1,
        ]);
        $this->Articles->save($article);
        $articleId = $article->id;

        // Clear any table instances to reset state
        $this->getTableLocator()->clear();
        $this->Articles = $this->fetchTable('TestApp.Articles');

        // Now add behavior with only 'add' and 'delete' requiring approval (not 'edit')
        $this->Articles->addBehavior('Bouncer.Bouncer');
        $behavior = $this->Articles->getBehavior('Bouncer');
        $behavior->setConfig('requireApproval', ['add', 'delete'], false);

        // Verify the configuration is correct
        $this->assertEquals(['add', 'delete'], $behavior->getConfig('requireApproval'));

        // Patch the article to ensure it has dirty fields
        $article = $this->Articles->get($articleId);
        $this->Articles->patchEntity($article, ['title' => 'Updated']);
        $result = $this->Articles->save($article, ['bouncerUserId' => 1, 'atomic' => true]);

        // Edit should NOT be bounced (only 'add' and 'delete' require approval)
        $this->assertNotFalse($result, 'Edit operation should succeed when edit does not require approval');
        $this->assertFalse($behavior->wasBounced());

        // Verify the article was updated
        $article = $this->Articles->get($articleId);
        $this->assertEquals('Updated', $article->title);

        // Verify no bouncer record was created for the edit
        $this->assertEquals(0, $this->BouncerRecords->find()->count());
    }

    /**
     * Test loadDraft method
     */
    public function testLoadDraft(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer');

        // Create initial article
        $article = $this->Articles->newEntity([
            'title' => 'Original',
            'body' => 'Original',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        // Create draft
        $article = $this->Articles->get($articleId);
        $article->title = 'Draft Title';
        $this->Articles->save($article, ['bouncerUserId' => 1]);

        // Load draft
        $draft = $this->Articles->getBehavior('Bouncer')->loadDraft($articleId, 1);

        $this->assertNotNull($draft);
        $this->assertEquals('TestApp.Articles', $draft->source);
        $this->assertEquals($articleId, $draft->primary_key);

        $draftData = $draft->getData();
        $this->assertEquals('Draft Title', $draftData['title']);
    }

    /**
     * Test hasPendingDraft method
     */
    public function testHasPendingDraft(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer');

        // Create article
        $article = $this->Articles->newEntity([
            'title' => 'Test',
            'body' => 'Test',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);

        // No draft yet
        $hasDraft = $this->Articles->getBehavior('Bouncer')->hasPendingDraft($article->id, 1);
        $this->assertFalse($hasDraft);

        // Create draft
        $article = $this->Articles->get($article->id);
        $article->title = 'Updated';
        $this->Articles->save($article, ['bouncerUserId' => 1]);

        // Now has draft
        $hasDraft = $this->Articles->getBehavior('Bouncer')->hasPendingDraft($article->id, 1);
        $this->assertTrue($hasDraft);
    }

    /**
     * Test applyApprovedChanges for new record
     */
    public function testApplyApprovedChangesNewRecord(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer');

        // Create draft
        $article = $this->Articles->newEntity([
            'title' => 'New Article',
            'body' => 'Content',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bouncerUserId' => 1]);

        $bouncerRecord = $this->BouncerRecords->find()->first();

        // Apply approved changes
        $entity = $this->Articles->getBehavior('Bouncer')->applyApprovedChanges($bouncerRecord);

        $this->assertNotFalse($entity);
        $this->assertEquals('New Article', $entity->title);

        // Article should now exist
        $count = $this->Articles->find()->count();
        $this->assertEquals(1, $count);
    }

    /**
     * Test applyApprovedChanges for edit
     */
    public function testApplyApprovedChangesEdit(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer');

        // Create original article
        $article = $this->Articles->newEntity([
            'title' => 'Original',
            'body' => 'Original',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        // Create draft edit
        $article = $this->Articles->get($articleId);
        $article->title = 'Updated';
        $this->Articles->save($article, ['bouncerUserId' => 1]);

        $bouncerRecord = $this->BouncerRecords->find()->first();

        // Apply approved changes
        $entity = $this->Articles->getBehavior('Bouncer')->applyApprovedChanges($bouncerRecord);

        $this->assertNotFalse($entity);
        $this->assertEquals('Updated', $entity->title);

        // Verify article was updated
        $article = $this->Articles->get($articleId);
        $this->assertEquals('Updated', $article->title);
    }

    /**
     * Test that original_data is populated correctly for edits
     *
     * @return void
     */
    public function testOriginalDataIsStoredForEdits(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'userField' => 'user_id',
            'requireApproval' => ['add', 'edit'],
        ]);

        // Create an article first
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $article = $this->Articles->save($article, ['bypassBouncer' => true]);
        $this->assertNotFalse($article);
        $articleId = $article->id;

        // Now edit it (should create bouncer record with original_data)
        $article = $this->Articles->get($articleId);
        $article->title = 'Updated Title';
        $article->body = 'Updated Body';

        $result = $this->Articles->save($article, ['bouncerUserId' => 1]);
        $this->assertFalse($result); // Should be intercepted by bouncer

        $this->assertTrue($this->Articles->getBehavior('Bouncer')->wasBounced());

        // Check that bouncer record has original_data populated
        $bouncerRecord = $this->Articles->getBehavior('Bouncer')->getLastBouncerRecord();
        $this->assertNotNull($bouncerRecord);

        $originalData = json_decode($bouncerRecord->original_data, true);
        $this->assertNotEmpty($originalData, 'original_data should not be empty for edits');
        $this->assertEquals('Original Title', $originalData['title']);
        $this->assertEquals('Original Body', $originalData['body']);

        // Check that proposed data has the updates
        $proposedData = json_decode($bouncerRecord->data, true);
        $this->assertEquals('Updated Title', $proposedData['title']);
        $this->assertEquals('Updated Body', $proposedData['body']);
    }

    /**
     * Test that original_data contains all fields, not just dirty fields
     *
     * This tests the fix for the bug where freshly loaded entities
     * have no dirty fields, causing original_data to be empty
     *
     * @return void
     */
    public function testOriginalDataContainsAllFieldsNotJustDirty(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'userField' => 'user_id',
            'requireApproval' => ['add', 'edit'],
        ]);

        // Create an article with multiple fields
        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test Body',
            'user_id' => 1,
        ]);
        $article = $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        // Edit only one field
        $article = $this->Articles->get($articleId);
        $article->title = 'Updated Title';
        // body is NOT changed

        $this->Articles->save($article, ['bouncerUserId' => 1]);

        $bouncerRecord = $this->Articles->getBehavior('Bouncer')->getLastBouncerRecord();
        $originalData = json_decode($bouncerRecord->original_data, true);

        // original_data should contain ALL fields from the original article
        // not just the dirty ones
        $this->assertArrayHasKey('title', $originalData);
        $this->assertArrayHasKey('body', $originalData);
        $this->assertArrayHasKey('user_id', $originalData);
        $this->assertEquals('Test Article', $originalData['title']);
        $this->assertEquals('Test Body', $originalData['body']);
    }

    /**
     * Test that delete creates bouncer record instead of deleting
     */
    public function testBeforeDeleteRecord(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'requireApproval' => ['add', 'edit', 'delete'],
        ]);

        // Create an article first
        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        // Try to delete it
        $article = $this->Articles->get($articleId);
        $result = $this->Articles->delete($article, ['bouncerUserId' => 2]);

        // Delete should be bounced
        $this->assertFalse($result);
        $this->assertTrue($this->Articles->getBehavior('Bouncer')->wasBounced());

        // Bouncer record should be created
        $bouncerRecord = $this->BouncerRecords->find()->first();
        $this->assertNotNull($bouncerRecord);
        $this->assertEquals('TestApp.Articles', $bouncerRecord->source);
        $this->assertEquals($articleId, $bouncerRecord->primary_key);
        $this->assertEquals('pending', $bouncerRecord->status);
        $this->assertEquals(2, $bouncerRecord->user_id);

        // Check that it's marked as delete
        $data = $bouncerRecord->getData();
        $this->assertArrayHasKey('_delete', $data);
        $this->assertTrue($data['_delete']);

        // Original data should be stored
        $originalData = json_decode($bouncerRecord->original_data, true);
        $this->assertEquals('Test Article', $originalData['title']);
        $this->assertEquals('Test body', $originalData['body']);

        // Article should still exist
        $this->assertTrue($this->Articles->exists(['id' => $articleId]));
    }

    /**
     * Test that delete can be configured to not require approval
     */
    public function testDeleteConfigurationWithoutApproval(): void
    {
        // Verify default config includes 'delete'
        $this->Articles->addBehavior('Bouncer.Bouncer');
        $defaultRequireApproval = $this->Articles->getBehavior('Bouncer')->getConfig('requireApproval');
        $this->assertContains('delete', $defaultRequireApproval);

        // Remove behavior and re-add with custom config
        $this->Articles->removeBehavior('Bouncer');
        $this->Articles->addBehavior('Bouncer.Bouncer');

        // Configure to only require approval for 'add' and 'edit', not 'delete'
        $this->Articles->getBehavior('Bouncer')->setConfig('requireApproval', ['add', 'edit'], false);

        // Verify the config is correct (delete not in requireApproval)
        $requireApproval = $this->Articles->getBehavior('Bouncer')->getConfig('requireApproval');
        $this->assertNotContains('delete', $requireApproval);
        $this->assertContains('add', $requireApproval);
        $this->assertContains('edit', $requireApproval);
    }

    /**
     * Test bypass bouncer for delete
     */
    public function testBypassBouncerForDelete(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'requireApproval' => ['add', 'edit', 'delete'],
        ]);

        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        // Delete with bypass
        $article = $this->Articles->get($articleId);
        $result = $this->Articles->delete($article, ['bypassBouncer' => true]);

        // Should delete normally
        $this->assertNotFalse($result);
        $this->assertFalse($this->Articles->getBehavior('Bouncer')->wasBounced());

        // No bouncer record should be created
        $count = $this->BouncerRecords->find()->count();
        $this->assertEquals(0, $count);

        // Article should be deleted
        $this->assertFalse($this->Articles->exists(['id' => $articleId]));
    }

    /**
     * Test exempt users can delete directly
     */
    public function testExemptUsersCanDeleteDirectly(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'requireApproval' => ['add', 'edit', 'delete'],
            'exemptUsers' => [1, 2],
        ]);

        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        // Delete as exempt user
        $article = $this->Articles->get($articleId);
        $result = $this->Articles->delete($article, ['bouncerUserId' => 1]);

        // Should delete normally (user is exempt)
        $this->assertNotFalse($result);
        $this->assertFalse($this->Articles->getBehavior('Bouncer')->wasBounced());

        // Article should be deleted
        $this->assertFalse($this->Articles->exists(['id' => $articleId]));
    }

    /**
     * Test applyApprovedChanges for delete
     */
    public function testApplyApprovedChangesDelete(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'requireApproval' => ['add', 'edit', 'delete'],
        ]);

        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        // Create delete draft
        $article = $this->Articles->get($articleId);
        $this->Articles->delete($article, ['bouncerUserId' => 1]);

        // Verify article still exists
        $this->assertTrue($this->Articles->exists(['id' => $articleId]));

        $bouncerRecord = $this->BouncerRecords->find()->first();

        // Apply approved deletion
        $result = $this->Articles->getBehavior('Bouncer')->applyApprovedChanges($bouncerRecord);

        $this->assertNotFalse($result);

        // Article should now be deleted
        $this->assertFalse($this->Articles->exists(['id' => $articleId]));
    }

    /**
     * Test that getLastBouncerRecord works for delete operations
     */
    public function testGetLastBouncerRecordForDelete(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'requireApproval' => ['add', 'edit', 'delete'],
        ]);

        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        // Delete it (create bouncer record)
        $article = $this->Articles->get($articleId);
        $this->Articles->delete($article, ['bouncerUserId' => 1]);

        // Get last bouncer record
        $bouncerRecord = $this->Articles->getBehavior('Bouncer')->getLastBouncerRecord();

        $this->assertNotNull($bouncerRecord);
        $this->assertEquals('TestApp.Articles', $bouncerRecord->source);
        $this->assertEquals($articleId, $bouncerRecord->primary_key);

        // Verify it's a delete operation
        $data = $bouncerRecord->getData();
        $this->assertArrayHasKey('_delete', $data);
        $this->assertTrue($data['_delete']);
    }

    /**
     * Test withDraft() convenience method
     *
     * @return void
     */
    public function testWithDraft(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'userField' => 'user_id',
            'requireApproval' => ['add', 'edit'],
        ]);

        // Create article
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $article = $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        // Create draft
        $article = $this->Articles->get($articleId);
        $article->title = 'Draft Title';
        $article->body = 'Draft Body';
        $this->Articles->save($article, ['bouncerUserId' => 1]);

        // Load fresh article and apply draft using withDraft()
        $article = $this->Articles->get($articleId);
        $this->assertEquals('Original Title', $article->title);
        $this->assertEquals('Original Body', $article->body);

        // Apply draft - now returns the draft entity instead of bool
        $draft = $this->Articles->getBehavior('Bouncer')->withDraft($article, 1);

        $this->assertNotNull($draft);
        $this->assertInstanceOf(BouncerRecord::class, $draft);
        $this->assertEquals('Draft Title', $article->title);
        $this->assertEquals('Draft Body', $article->body);

        // Verify we can access original data from the draft
        $originalData = $draft->getOriginalData();
        $this->assertEquals('Original Title', $originalData['title']);
        $this->assertEquals('Original Body', $originalData['body']);
    }

    /**
     * Test withDraft() returns null when no draft exists
     *
     * @return void
     */
    public function testWithDraftNoDraft(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'userField' => 'user_id',
            'requireApproval' => ['add', 'edit'],
        ]);

        // Create article
        $article = $this->Articles->newEntity([
            'title' => 'Test Title',
            'body' => 'Test Body',
            'user_id' => 1,
        ]);
        $article = $this->Articles->save($article, ['bypassBouncer' => true]);

        // Try to apply draft when none exists
        $article = $this->Articles->get($article->id);
        $draft = $this->Articles->getBehavior('Bouncer')->withDraft($article, 1);

        $this->assertNull($draft);
        $this->assertEquals('Test Title', $article->title);
        $this->assertEquals('Test Body', $article->body);
    }

    /**
     * Test that multiple delete requests update existing draft instead of creating duplicates
     *
     * @return void
     */
    public function testDeleteAutoSupersedePreventsMultipleDrafts(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'requireApproval' => ['add', 'edit', 'delete'],
            'autoSupersede' => true,
        ]);

        // Create an article first
        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        // Request deletion
        $article = $this->Articles->get($articleId);
        $this->Articles->delete($article, ['bouncerUserId' => 1]);

        // Should have 1 bouncer record
        $count = $this->BouncerRecords->find()->where(['primary_key' => $articleId])->count();
        $this->assertEquals(1, $count);

        $firstBouncerRecord = $this->BouncerRecords->find()->where(['primary_key' => $articleId])->first();
        $firstBouncerId = $firstBouncerRecord->id;

        // Request deletion again (same user, same article)
        $article = $this->Articles->get($articleId);
        $this->Articles->delete($article, ['bouncerUserId' => 1]);

        // Should still have only 1 bouncer record (updated, not duplicated)
        $count = $this->BouncerRecords->find()->where(['primary_key' => $articleId, 'status' => 'pending'])->count();
        $this->assertEquals(1, $count);

        // Should be the same bouncer record ID (updated existing)
        $secondBouncerRecord = $this->BouncerRecords->find()->where(['primary_key' => $articleId, 'status' => 'pending'])->first();
        $this->assertEquals($firstBouncerId, $secondBouncerRecord->id);

        // Verify it's still a delete operation
        $data = $secondBouncerRecord->getData();
        $this->assertArrayHasKey('_delete', $data);
        $this->assertTrue($data['_delete']);
    }

    /**
     * Test that delete request supersedes pending edit draft
     *
     * @return void
     */
    public function testDeleteSupersedesPendingEdit(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'requireApproval' => ['add', 'edit', 'delete'],
            'autoSupersede' => true,
        ]);

        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        // Create a pending edit
        $article = $this->Articles->get($articleId);
        $article->title = 'Updated Title';
        $this->Articles->save($article, ['bouncerUserId' => 1]);

        // Should have 1 pending edit
        $editRecord = $this->BouncerRecords->find()
            ->where(['primary_key' => $articleId, 'status' => 'pending'])
            ->first();
        $this->assertNotNull($editRecord);

        $editData = $editRecord->getData();
        $this->assertEquals('Updated Title', $editData['title']);

        // Now request deletion (should supersede the edit)
        $article = $this->Articles->get($articleId);
        $this->Articles->delete($article, ['bouncerUserId' => 1]);

        // Should still have 1 pending record
        $count = $this->BouncerRecords->find()
            ->where(['primary_key' => $articleId, 'status' => 'pending'])
            ->count();
        $this->assertEquals(1, $count);

        // The pending record should now be a delete (superseded the edit)
        $deleteRecord = $this->BouncerRecords->find()
            ->where(['primary_key' => $articleId, 'status' => 'pending'])
            ->first();

        $deleteData = $deleteRecord->getData();
        $this->assertArrayHasKey('_delete', $deleteData);
        $this->assertTrue($deleteData['_delete']);

        // Original edit should be superseded
        $supersededCount = $this->BouncerRecords->find()
            ->where(['primary_key' => $articleId, 'status' => 'superseded'])
            ->count();
        $this->assertEquals(1, $supersededCount);
    }

    /**
     * Test that edit request supersedes pending delete draft
     *
     * @return void
     */
    public function testEditSupersedesPendingDelete(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'requireApproval' => ['add', 'edit', 'delete'],
            'autoSupersede' => true,
        ]);

        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        // Request deletion
        $article = $this->Articles->get($articleId);
        $this->Articles->delete($article, ['bouncerUserId' => 1]);

        // Should have 1 pending delete
        $deleteRecord = $this->BouncerRecords->find()
            ->where(['primary_key' => $articleId, 'status' => 'pending'])
            ->first();
        $this->assertNotNull($deleteRecord);

        $deleteData = $deleteRecord->getData();
        $this->assertArrayHasKey('_delete', $deleteData);
        $this->assertTrue($deleteData['_delete']);

        // Now make an edit (should supersede the delete)
        $article = $this->Articles->get($articleId);
        $article->title = 'Updated Title';
        $this->Articles->save($article, ['bouncerUserId' => 1]);

        // Should still have 1 pending record
        $count = $this->BouncerRecords->find()
            ->where(['primary_key' => $articleId, 'status' => 'pending'])
            ->count();
        $this->assertEquals(1, $count);

        // The pending record should now be an edit (superseded the delete)
        $editRecord = $this->BouncerRecords->find()
            ->where(['primary_key' => $articleId, 'status' => 'pending'])
            ->first();

        $editData = $editRecord->getData();
        $this->assertArrayNotHasKey('_delete', $editData);
        $this->assertEquals('Updated Title', $editData['title']);

        // Original delete should be superseded
        $supersededCount = $this->BouncerRecords->find()
            ->where(['primary_key' => $articleId, 'status' => 'superseded'])
            ->count();
        $this->assertEquals(1, $supersededCount);
    }

    /**
     * Test that reverting changes to original removes pending draft
     *
     * @return void
     */
    public function testRevertingChangesRemovesPendingDraft()
    {
        // Create a test article first
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        // Add bouncer behavior
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'requireApproval' => ['edit'],
            'autoSupersede' => true,
        ]);

        // First, create a pending edit
        $article = $this->Articles->get($articleId);
        $article = $this->Articles->patchEntity($article, [
            'title' => 'Changed Title',
            'body' => 'Changed Body',
        ]);
        $this->Articles->save($article, ['bouncerUserId' => 2]);

        // Verify draft was created
        $pendingCount = $this->BouncerRecords->find()
            ->where(['primary_key' => $articleId, 'status' => 'pending'])
            ->count();
        $this->assertEquals(1, $pendingCount, 'Should have 1 pending draft');

        // Now edit again, reverting to original values
        $article = $this->Articles->get($articleId);
        $article = $this->Articles->patchEntity($article, [
            'title' => 'Original Title',
            'body' => 'Original Body',
        ]);
        // Force dirty to trigger beforeSave callback
        $article->setDirty('title', true);
        $this->Articles->save($article, ['bouncerUserId' => 2]);

        // Verify draft was removed
        $pendingCount = $this->BouncerRecords->find()
            ->where(['primary_key' => $articleId, 'status' => 'pending'])
            ->count();
        $this->assertEquals(0, $pendingCount, 'Pending draft should be removed when reverted to original');
    }

    /**
     * Regression: with the prior loose `!=` comparison, editing a string
     * column from `"0"` to the literal boolean `false` was classified as a
     * revert (`'0' == false`), and the user's intended draft was silently
     * deleted. With the cast-to-string strict comparison, the edit creates
     * the draft as expected.
     *
     * @return void
     */
    public function testFalsyEditDoesNotFalselyTriggerRevert(): void
    {
        $article = $this->Articles->newEntity([
            'title' => '0', // string zero — the foot-gun input
            'body' => 'Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'requireApproval' => ['edit'],
            'autoSupersede' => true,
        ]);

        // First proposal: change title from "0" to "Real Edit".
        $article = $this->Articles->get($articleId);
        $article = $this->Articles->patchEntity($article, ['title' => 'Real Edit']);
        $this->Articles->save($article, ['bouncerUserId' => 2]);

        $pendingCount = $this->BouncerRecords->find()
            ->where(['primary_key' => $articleId, 'status' => 'pending'])
            ->count();
        $this->assertEquals(1, $pendingCount, 'First edit should create a draft.');

        // Now re-edit, this time to a value that *loosely* equals the
        // original ("0" == false). Under the old behavior this matched the
        // original and dropped the draft. Under the strict comparison the
        // draft is kept (these are different values).
        $article = $this->Articles->get($articleId);
        $article->set('title', false);
        $article->setDirty('title', true);
        $this->Articles->save($article, ['bouncerUserId' => 2]);

        $pendingCount = $this->BouncerRecords->find()
            ->where(['primary_key' => $articleId, 'status' => 'pending'])
            ->count();
        $this->assertEquals(
            1,
            $pendingCount,
            'Edit to boolean false (loosely equal to string "0") must NOT be classified as a revert.',
        );
    }

    /**
     * Test bypassCallback allows custom bypass logic
     */
    public function testBypassCallbackAllowsBypass(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'bypassCallback' => function ($entity, $options, $table) {
                // Allow bypass if user_id is 99 (custom logic)
                $userId = $options['bouncerUserId'] ?? $entity->get('user_id');

                return $userId === 99;
            },
        ]);

        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);

        // User 99 should bypass (not be bounced)
        $result = $this->Articles->save($article, ['bouncerUserId' => 99]);
        $this->assertNotFalse($result);
        $this->assertFalse($this->Articles->getBehavior('Bouncer')->wasBounced());

        // Article should exist
        $this->assertEquals(1, $this->Articles->find()->count());
    }

    /**
     * Test bypassCallback denies bypass when returning false
     */
    public function testBypassCallbackDeniesWhenReturnsFalse(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'bypassCallback' => function ($entity, $options, $table) {
                // Only allow bypass if user_id is 99
                $userId = $options['bouncerUserId'] ?? $entity->get('user_id');

                return $userId === 99;
            },
        ]);

        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);

        // User 1 should NOT bypass (should be bounced)
        $result = $this->Articles->save($article, ['bouncerUserId' => 1]);
        $this->assertFalse($result);
        $this->assertTrue($this->Articles->getBehavior('Bouncer')->wasBounced());

        // No article should exist, only bouncer record
        $this->assertEquals(0, $this->Articles->find()->count());
        $this->assertEquals(1, $this->BouncerRecords->find()->count());
    }

    /**
     * Test bypassCallback receives correct parameters
     */
    public function testBypassCallbackParameters(): void
    {
        $receivedEntity = null;
        $receivedOptions = null;
        $receivedTable = null;

        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'bypassCallback' => function ($entity, $options, $table) use (&$receivedEntity, &$receivedOptions, &$receivedTable) {
                $receivedEntity = $entity;
                $receivedOptions = $options;
                $receivedTable = $table;

                return true; // Allow bypass
            },
        ]);

        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);

        $this->Articles->save($article, ['bouncerUserId' => 1]);

        // Verify callback received correct parameters
        $this->assertInstanceOf('Cake\Datasource\EntityInterface', $receivedEntity);
        $this->assertInstanceOf('ArrayObject', $receivedOptions);
        $this->assertInstanceOf('Cake\ORM\Table', $receivedTable);
        $this->assertEquals('Test Article', $receivedEntity->title);
        $this->assertEquals(1, $receivedOptions['bouncerUserId']);
    }

    /**
     * Test bypassCallback works with entity-based decisions
     */
    public function testBypassCallbackEntityBasedDecision(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'bypassCallback' => function ($entity, $options, $table) {
                // Allow bypass only for articles with specific title
                return $entity->title === 'Admin Article';
            },
        ]);

        // Article with special title should bypass
        $article1 = $this->Articles->newEntity([
            'title' => 'Admin Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);
        $result1 = $this->Articles->save($article1, ['bouncerUserId' => 1]);
        $this->assertNotFalse($result1);

        // Regular article should be bounced
        $article2 = $this->Articles->newEntity([
            'title' => 'Regular Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);
        $result2 = $this->Articles->save($article2, ['bouncerUserId' => 1]);
        $this->assertFalse($result2);

        // Should have 1 real article and 1 bouncer record
        $this->assertEquals(1, $this->Articles->find()->count());
        $this->assertEquals(1, $this->BouncerRecords->find()->count());
    }

    /**
     * Test bypassCallback works for delete operations
     */
    public function testBypassCallbackForDelete(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'requireApproval' => ['add', 'edit', 'delete'],
            'bypassCallback' => function ($entity, $options, $table) {
                // Allow delete bypass if user_id is 99
                $userId = $options['bouncerUserId'] ?? null;

                return $userId === 99;
            },
        ]);

        // Create article
        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        // Delete as user 99 (should bypass)
        $article = $this->Articles->get($articleId);
        $result = $this->Articles->delete($article, ['bouncerUserId' => 99]);

        $this->assertNotFalse($result);
        $this->assertFalse($this->Articles->getBehavior('Bouncer')->wasBounced());
        $this->assertFalse($this->Articles->exists(['id' => $articleId]));
    }

    /**
     * Test exemptUsers still works when bypassCallback is set (backward compatibility)
     */
    public function testExemptUsersWithBypassCallback(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'exemptUsers' => [1],
            'bypassCallback' => function ($entity, $options, $table) {
                // Callback only allows user 99
                $userId = $options['bouncerUserId'] ?? $entity->get('user_id');

                return $userId === 99;
            },
        ]);

        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);

        // User 99 should bypass (via callback)
        $result1 = $this->Articles->save($article, ['bouncerUserId' => 99]);
        $this->assertNotFalse($result1);

        // User 1 should also bypass (via exemptUsers fallback)
        $article2 = $this->Articles->newEntity([
            'title' => 'Test Article 2',
            'body' => 'Test body',
            'user_id' => 1,
        ]);
        $result2 = $this->Articles->save($article2, ['bouncerUserId' => 1]);
        $this->assertNotFalse($result2);

        // Both articles should exist
        $this->assertEquals(2, $this->Articles->find()->count());
    }

    /**
     * Test that note is stored when creating new record draft
     */
    public function testNoteStoredOnNewRecord(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer');

        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);

        $result = $this->Articles->save($article, [
            'bouncerUserId' => 1,
            'bouncerNote' => 'Adding new article for documentation',
        ]);

        $this->assertFalse($result);
        $this->assertTrue($this->Articles->getBehavior('Bouncer')->wasBounced());

        $bouncerRecord = $this->BouncerRecords->find()->first();
        $this->assertNotNull($bouncerRecord);
        $this->assertEquals('Adding new article for documentation', $bouncerRecord->note);
    }

    /**
     * Test that note is stored when editing record
     */
    public function testNoteStoredOnEditRecord(): void
    {
        // Create article first
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        $this->Articles->addBehavior('Bouncer.Bouncer');

        // Edit the article with a note
        $article = $this->Articles->get($articleId);
        $article->title = 'Updated Title';
        $result = $this->Articles->save($article, [
            'bouncerUserId' => 2,
            'bouncerNote' => 'Fixing typo in title',
        ]);

        $this->assertFalse($result);

        $bouncerRecord = $this->BouncerRecords->find()->first();
        $this->assertNotNull($bouncerRecord);
        $this->assertEquals('Fixing typo in title', $bouncerRecord->note);
    }

    /**
     * Test that note is stored when deleting record
     */
    public function testNoteStoredOnDeleteRecord(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'requireApproval' => ['add', 'edit', 'delete'],
        ]);

        // Create article first
        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        // Delete with note
        $article = $this->Articles->get($articleId);
        $result = $this->Articles->delete($article, [
            'bouncerUserId' => 2,
            'bouncerNote' => 'Content is outdated and no longer relevant',
        ]);

        $this->assertFalse($result);

        $bouncerRecord = $this->BouncerRecords->find()->first();
        $this->assertNotNull($bouncerRecord);
        $this->assertEquals('Content is outdated and no longer relevant', $bouncerRecord->note);
        $this->assertTrue($bouncerRecord->isDeleteProposal());
    }

    /**
     * Test that note is optional (can be null)
     */
    public function testNoteIsOptional(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer');

        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);

        $result = $this->Articles->save($article, ['bouncerUserId' => 1]);

        $this->assertFalse($result);

        $bouncerRecord = $this->BouncerRecords->find()->first();
        $this->assertNotNull($bouncerRecord);
        $this->assertNull($bouncerRecord->note);
    }

    /**
     * Test that note is truncated to 255 characters
     */
    public function testNoteTruncatedTo255Chars(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer');

        $longNote = str_repeat('a', 300);

        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);

        $result = $this->Articles->save($article, [
            'bouncerUserId' => 1,
            'bouncerNote' => $longNote,
        ]);

        $this->assertFalse($result);

        $bouncerRecord = $this->BouncerRecords->find()->first();
        $this->assertNotNull($bouncerRecord);
        $this->assertEquals(255, mb_strlen($bouncerRecord->note));
        $this->assertEquals(str_repeat('a', 255), $bouncerRecord->note);
    }

    /**
     * Test that note is updated when editing existing draft
     */
    public function testNoteUpdatedOnExistingDraft(): void
    {
        // Create article first
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        $this->Articles->addBehavior('Bouncer.Bouncer');

        // Create first draft with note
        $article = $this->Articles->get($articleId);
        $article->title = 'First Update';
        $this->Articles->save($article, [
            'bouncerUserId' => 1,
            'bouncerNote' => 'First reason',
        ]);

        $bouncerRecord = $this->BouncerRecords->find()->first();
        $this->assertEquals('First reason', $bouncerRecord->note);

        // Update draft with new note
        $article = $this->Articles->get($articleId);
        $article->title = 'Second Update';
        $this->Articles->save($article, [
            'bouncerUserId' => 1,
            'bouncerNote' => 'Updated reason',
        ]);

        // Should still be 1 pending record
        $count = $this->BouncerRecords->find()->where(['status' => 'pending'])->count();
        $this->assertEquals(1, $count);

        $bouncerRecord = $this->BouncerRecords->find()->where(['status' => 'pending'])->first();
        $this->assertEquals('Updated reason', $bouncerRecord->note);
    }

    /**
     * Test that note is preserved when updating draft without new note
     */
    public function testNotePreservedWhenUpdatingWithoutNewNote(): void
    {
        // Create article first
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        $this->Articles->addBehavior('Bouncer.Bouncer');

        // Create first draft with note
        $article = $this->Articles->get($articleId);
        $article->title = 'First Update';
        $this->Articles->save($article, [
            'bouncerUserId' => 1,
            'bouncerNote' => 'Initial reason',
        ]);

        $bouncerRecord = $this->BouncerRecords->find()->first();
        $this->assertEquals('Initial reason', $bouncerRecord->note);

        // Update draft without note (should preserve original note)
        $article = $this->Articles->get($articleId);
        $article->title = 'Second Update';
        $this->Articles->save($article, [
            'bouncerUserId' => 1,
        ]);

        $bouncerRecord = $this->BouncerRecords->find()->where(['status' => 'pending'])->first();
        $this->assertEquals('Initial reason', $bouncerRecord->note);
    }

    /**
     * Test that empty string note is treated as no note
     */
    public function testEmptyStringNoteIsNull(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer');

        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);

        $result = $this->Articles->save($article, [
            'bouncerUserId' => 1,
            'bouncerNote' => '',
        ]);

        $this->assertFalse($result);

        $bouncerRecord = $this->BouncerRecords->find()->first();
        $this->assertNotNull($bouncerRecord);
        // Empty string is stored as empty string
        $this->assertEquals('', $bouncerRecord->note);
    }

    /**
     * Test that wasDraftRemoved returns true when draft is removed due to revert
     *
     * This tests the fix for the bug where removeRevertedDraft used getRegistryAlias()
     * but drafts were created with getAlias(), causing lookup mismatches.
     *
     * @return void
     */
    public function testWasDraftRemovedReturnsTrue(): void
    {
        // Create a test article first
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        // Add bouncer behavior
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'requireApproval' => ['edit'],
        ]);

        $bouncer = $this->Articles->getBehavior('Bouncer');

        // Create a pending edit
        $article = $this->Articles->get($articleId);
        $article = $this->Articles->patchEntity($article, [
            'title' => 'Changed Title',
        ]);
        $this->Articles->save($article, ['bouncerUserId' => 2]);

        // Verify draft was created
        $this->assertTrue($bouncer->hasPendingDraft($articleId, 2));
        $this->assertFalse($bouncer->wasDraftRemoved());
        $this->assertTrue($bouncer->wasBounced(), 'First save should have been bounced');

        // Simulate the real-world scenario: load the entity and apply the draft
        // This is what happens in the propose action: user sees their draft content
        $article = $this->Articles->get($articleId);
        $bouncer->withDraft($article, 2);
        // Now entity has title = "Changed Title" (the draft content)

        // User reverts by submitting the original content
        $article = $this->Articles->patchEntity($article, [
            'title' => 'Original Title', // Different from draft, same as original = revert
        ]);

        // Entity should be dirty (title changed from draft content to original)
        $this->assertTrue($article->isDirty('title'));

        $this->Articles->save($article, ['bouncerUserId' => 2]);

        // Draft should be removed since content matches original
        $this->assertTrue($bouncer->wasDraftRemoved());
        $this->assertFalse($bouncer->hasPendingDraft($articleId, 2));
    }

    /**
     * Test that source alias is consistent between create and lookup
     *
     * This ensures that drafts created with getRegistryAlias() can be found by
     * removeRevertedDraft which should also use getRegistryAlias().
     *
     * @return void
     */
    public function testSourceAliasConsistency(): void
    {
        // Create a test article
        $article = $this->Articles->newEntity([
            'title' => 'Test Title',
            'body' => 'Test Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'requireApproval' => ['edit'],
        ]);

        // Create draft
        $article = $this->Articles->get($articleId);
        $article->title = 'Changed';
        $this->Articles->save($article, ['bouncerUserId' => 1]);

        // Verify the source is the full registry alias (including plugin prefix)
        $bouncerRecord = $this->BouncerRecords->find()->first();
        $this->assertNotNull($bouncerRecord);
        $this->assertEquals('TestApp.Articles', $bouncerRecord->source);
    }

    /**
     * Test that applyApprovedChanges auto-merges stale edit proposals.
     *
     * Scenario:
     * 1. Owner has content "Hello!!!! World" (original)
     * 2. Contributor proposes change: "Hello!!!! Universe" (proposed - changes "World" to "Universe")
     * 3. Owner edits their article: "Hello World" (current - removes "!!!!")
     * 4. When approved, result should be "Hello Universe" (merged - both changes preserved)
     *
     * @return void
     */
    public function testApplyApprovedChangesAutoMergesStaleProposal(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer');

        // Create original article
        $originalBody = 'Hello!!!! World';
        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => $originalBody,
            'user_id' => 1,
        ]);
        $article = $this->Articles->save($article, ['bypassBouncer' => true]);
        $this->assertNotFalse($article);
        $articleId = $article->id;
        // Store the original modified time (go back 1 second to ensure staleness detection)
        $originalModified = $article->modified->subSeconds(1);

        // Simulate a proposal that was created BEFORE owner edited
        // Contributor changes "World" to "Universe" but keeps "!!!!"
        $proposedBody = 'Hello!!!! Universe';
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $articleId,
            'user_id' => 2, // Different user (contributor)
            'status' => 'pending',
            'data' => json_encode(['body' => $proposedBody]),
            'original_data' => json_encode(['body' => $originalBody]),
            'original_modified' => $originalModified,
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        // Owner edits their article - removes "!!!!"
        $currentBody = 'Hello World';
        $article = $this->Articles->get($articleId);
        $article->body = $currentBody;
        $this->Articles->save($article, ['bypassBouncer' => true]);

        // Verify article was updated
        $article = $this->Articles->get($articleId);
        $this->assertSame($currentBody, $article->body);

        // Now apply the stale proposal - it should auto-merge
        $bouncerRecord = $this->BouncerRecords->get($bouncerRecord->id);
        $result = $this->Articles->getBehavior('Bouncer')->applyApprovedChanges($bouncerRecord);

        $this->assertNotFalse($result);

        // The merged result should be "Hello Universe"
        // - Owner's removal of "!!!!" is preserved
        // - Contributor's "World" -> "Universe" change is applied
        $article = $this->Articles->get($articleId);
        $expectedBody = 'Hello Universe';
        $this->assertSame(
            $expectedBody,
            $article->body,
            sprintf(
                '3-way merge failed. Expected "%s", got "%s". Original: "%s", Current: "%s", Proposed: "%s"',
                $expectedBody,
                $article->body,
                $originalBody,
                $currentBody,
                $proposedBody,
            ),
        );
    }

    /**
     * Test that applyApprovedChanges can be configured to skip auto-merge.
     *
     * @return void
     */
    public function testApplyApprovedChangesAutoMergeCanBeDisabled(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer');

        // Create original article
        $originalBody = 'Hello!!!! World';
        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => $originalBody,
            'user_id' => 1,
        ]);
        $article = $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;
        $originalModified = $article->modified->subSeconds(1);

        // Create stale proposal
        $proposedBody = 'Hello!!!! Universe';
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $articleId,
            'user_id' => 2,
            'status' => 'pending',
            'data' => json_encode(['body' => $proposedBody]),
            'original_data' => json_encode(['body' => $originalBody]),
            'original_modified' => $originalModified,
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        // Owner edits their article
        $currentBody = 'Hello World';
        $article = $this->Articles->get($articleId);
        $article->body = $currentBody;
        $this->Articles->save($article, ['bypassBouncer' => true]);

        // Apply with autoMerge disabled - should use proposed data as-is
        $bouncerRecord = $this->BouncerRecords->get($bouncerRecord->id);
        $result = $this->Articles->getBehavior('Bouncer')->applyApprovedChanges($bouncerRecord, ['autoMerge' => false]);

        $this->assertNotFalse($result);

        // Without auto-merge, the proposed value should be applied directly
        $article = $this->Articles->get($articleId);
        $this->assertSame($proposedBody, $article->body);
    }

    /**
     * Test that pre-merged data takes precedence over auto-merge.
     *
     * @return void
     */
    public function testApplyApprovedChangesRespectsPreMergedData(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer');

        // Create original article
        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $article = $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;
        $originalModified = $article->modified->subSeconds(1);

        // Create stale proposal
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $articleId,
            'user_id' => 2,
            'status' => 'pending',
            'data' => json_encode(['body' => 'Proposed Body']),
            'original_data' => json_encode(['body' => 'Original Body']),
            'original_modified' => $originalModified,
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        // Owner edits their article
        $article = $this->Articles->get($articleId);
        $article->body = 'Current Body';
        $this->Articles->save($article, ['bypassBouncer' => true]);

        // Pre-set merged data - this should take precedence
        $bouncerRecord = $this->BouncerRecords->get($bouncerRecord->id);
        $bouncerRecord->setMergedData(['body' => 'Custom Merged Body']);

        $result = $this->Articles->getBehavior('Bouncer')->applyApprovedChanges($bouncerRecord);

        $this->assertNotFalse($result);

        // The pre-merged data should be used, not auto-merge
        $article = $this->Articles->get($articleId);
        $this->assertSame('Custom Merged Body', $article->body);
    }

    /**
     * Test that non-stale proposals don't trigger auto-merge.
     *
     * @return void
     */
    public function testApplyApprovedChangesNoMergeForFreshProposal(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer');

        // Create original article
        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $article = $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;
        // Set original_modified to the future so it's not stale
        $originalModified = $article->modified->addSeconds(10);

        // Create "fresh" proposal (original_modified is in the future)
        $proposedBody = 'Proposed Body';
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $articleId,
            'user_id' => 2,
            'status' => 'pending',
            'data' => json_encode(['body' => $proposedBody]),
            'original_data' => json_encode(['body' => 'Original Body']),
            'original_modified' => $originalModified,
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        // Apply - since not stale, should just use proposed data
        $bouncerRecord = $this->BouncerRecords->get($bouncerRecord->id);
        $result = $this->Articles->getBehavior('Bouncer')->applyApprovedChanges($bouncerRecord);

        $this->assertNotFalse($result);

        $article = $this->Articles->get($articleId);
        $this->assertSame($proposedBody, $article->body);
    }

    /**
     * Test that wrapping a Bouncer-enabled save() in a host transactional() block does not
     * silently commit the host transaction. Before the fix, the behavior force-committed
     * unconditionally, which closed the host's outer transaction and prevented a subsequent
     * rollback from reverting host-level changes.
     *
     * @return void
     */
    public function testHostTransactionalWrapDoesNotCommitOuterTransaction(): void
    {
        // Seed a non-Bouncer-controlled article so we can verify host rollback behavior
        // independently of the Bouncer table.
        $seed = $this->Articles->newEntity([
            'title' => 'Seed Title',
            'body' => 'Seed Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($seed, ['bypassBouncer' => true]);
        $seedId = $seed->id;

        $this->Articles->addBehavior('Bouncer.Bouncer');
        $connection = $this->Articles->getConnection();

        try {
            $connection->transactional(function () use ($seedId): bool {
                // (1) Mutate a host-table row directly (no Bouncer interception).
                $hostRow = $this->Articles->get($seedId);
                $hostRow->title = 'Mutated Inside Host Tx';
                $this->Articles->save($hostRow, ['bypassBouncer' => true]);

                // (2) Trigger a Bouncer-intercepted save in the same outer transaction.
                $article = $this->Articles->newEntity([
                    'title' => 'Bouncer Article',
                    'body' => 'Body',
                    'user_id' => 1,
                ]);
                $this->Articles->save($article, ['bouncerUserId' => 1]);

                // (3) Roll back the entire host transaction by returning false.
                return false;
            });
        } catch (Throwable) {
            // transactional() may surface as exception in some drivers; either way the
            // post-condition we care about is the rollback succeeded for host changes.
        }

        // The host's mutation in step (1) MUST have been rolled back. Before the fix,
        // step (2) would have force-committed and step (3)'s rollback would have been
        // a no-op for step (1).
        $hostRow = $this->Articles->get($seedId);
        $this->assertSame(
            'Seed Title',
            $hostRow->title,
            'Host transaction rollback was lost — Bouncer force-committed the outer transaction.',
        );
    }

    /**
     * Regression: if cake-core ever renames the protected `_transactionLevel`
     * property (it is a 4.x-style legacy convention), the behavior must NOT
     * silently flip into the unsafe "not wrapped → force-commit" branch.
     * Probing all known property names should fail-safe to `true` (treat as
     * host-wrapped and SKIP force-commit).
     *
     * Models the rename by subclassing the behavior and pointing its
     * candidate-property list at names that don't exist on the real
     * Connection class.
     *
     * @return void
     */
    public function testIsHostTransactionWrappedFailsSafeWhenPropertyMissing(): void
    {
        $this->Articles->addBehavior('Bouncer.Bouncer');
        $connection = $this->Articles->getConnection();

        $behavior = new class ($this->Articles) extends BouncerBehavior {
            /**
             * @var array
             */
            protected const TRANSACTION_LEVEL_CANDIDATES = ['__no_such_property__', '__also_missing__'];

            public function callIsHostTransactionWrapped(Connection $connection): bool
            {
                return $this->isHostTransactionWrapped($connection);
            }
        };

        $this->assertTrue(
            $behavior->callIsHostTransactionWrapped($connection),
            'When no candidate property is found on the connection, the safe default is to '
            . 'treat the transaction as host-wrapped (skip force-commit). Returning false here '
            . 'would mean force-committing on top of a host transaction — the dangerous direction.',
        );
    }
}
