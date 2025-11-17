<?php

declare(strict_types=1);

namespace Bouncer\Test\TestCase\Model\Behavior;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

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
        $this->assertEquals('Articles', $bouncerRecord->source);
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
        $this->assertEquals('Articles', $bouncerRecord->source);
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
        $this->assertEquals('Articles', $draft->source);
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
}
