<?php

declare(strict_types=1);

namespace Bouncer\Test\TestCase\Controller\Admin;

use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Bouncer\Controller\Admin\BouncerController Test Case
 *
 * @uses \Bouncer\Controller\Admin\BouncerController
 */
class BouncerControllerTest extends TestCase
{
    use IntegrationTestTrait;
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
     * @var \Bouncer\Model\Table\BouncerRecordsTable
     */
    protected $BouncerRecords;

    /**
     * @var \TestApp\Model\Table\ArticlesTable
     */
    protected $Articles;

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->BouncerRecords = $this->fetchTable('Bouncer.BouncerRecords');
        $this->Articles = $this->fetchTable('TestApp.Articles');

        $this->enableRetainFlashMessages();
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->BouncerRecords, $this->Articles);

        parent::tearDown();
    }

    /**
     * Test index method
     *
     * @return void
     */
    public function testIndex(): void
    {
        $this->get(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'index']);

        $this->assertResponseOk();
    }

    /**
     * Test index with status filter
     *
     * @return void
     */
    public function testIndexWithStatusFilter(): void
    {
        // Create a pending bouncer record
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => null,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Test']),
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->get(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'index', '?' => ['status' => 'pending']]);

        $this->assertResponseOk();
        $this->assertResponseContains('Articles');
    }

    /**
     * Test view method for new record proposal
     *
     * @return void
     */
    public function testViewNewRecordProposal(): void
    {
        // Create a pending new record bouncer record
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => null,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'New Article', 'body' => 'Content']),
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->get(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'view', $bouncerRecord->id]);

        $this->assertResponseOk();
    }

    /**
     * Test view method for edit proposal without conflict
     *
     * @return void
     */
    public function testViewEditProposalNoConflict(): void
    {
        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article);

        // Create a pending edit bouncer record with same original_modified as article
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $article->id,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Updated Title', 'body' => 'Original Body']),
            'original_data' => json_encode(['title' => 'Original Title', 'body' => 'Original Body']),
            'original_modified' => $article->modified,
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->get(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'view', $bouncerRecord->id]);

        $this->assertResponseOk();
    }

    /**
     * Test view method detects stale draft (conflict)
     *
     * @return void
     */
    public function testViewEditProposalWithConflict(): void
    {
        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article);
        $originalModified = $article->modified;

        // Update the article (simulate concurrent edit)
        $article->title = 'Updated by someone else';
        $article->modified = new DateTime('+1 hour');
        $this->Articles->save($article);

        // Create a pending edit bouncer record with old original_modified
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $article->id,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Draft Title', 'body' => 'Original Body']),
            'original_data' => json_encode(['title' => 'Original Title', 'body' => 'Original Body']),
            'original_modified' => $originalModified,
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->get(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'view', $bouncerRecord->id]);

        $this->assertResponseOk();
        // The view should detect the conflict and set it in the view vars
    }

    /**
     * Test view method when source record no longer exists
     *
     * @return void
     */
    public function testViewEditProposalRecordDeleted(): void
    {
        // Create a pending edit bouncer record for non-existent article
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => 99999,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Draft Title']),
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->get(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'view', $bouncerRecord->id]);

        $this->assertResponseOk();
        $this->assertFlashMessage('The original record no longer exists.');
    }

    /**
     * Test resolve method redirects for already processed record
     *
     * @return void
     */
    public function testResolveAlreadyProcessed(): void
    {
        // Create an approved bouncer record
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => 1,
            'user_id' => 1,
            'status' => 'approved',
            'data' => json_encode(['title' => 'Test']),
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->get(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'resolve', $bouncerRecord->id]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('This record has already been processed.');
    }

    /**
     * Test resolve method redirects for new record proposals
     *
     * @return void
     */
    public function testResolveNewRecordProposal(): void
    {
        // Create a pending new record proposal
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => null,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'New Article']),
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->get(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'resolve', $bouncerRecord->id]);

        $this->assertRedirect(['action' => 'view', $bouncerRecord->id]);
        $this->assertFlashMessage('Conflict resolution is only available for edit proposals.');
    }

    /**
     * Test resolve method redirects when source record no longer exists
     *
     * @return void
     */
    public function testResolveRecordDeleted(): void
    {
        // Create a pending edit bouncer record for non-existent article
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => 99999,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Draft Title']),
            'original_modified' => new DateTime('-1 hour'),
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->get(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'resolve', $bouncerRecord->id]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('The original record no longer exists.');
    }

    /**
     * Test resolve method redirects when no conflict detected
     *
     * @return void
     */
    public function testResolveNoConflict(): void
    {
        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article);

        // Create a pending edit bouncer record with same original_modified
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $article->id,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Updated Title']),
            'original_data' => json_encode(['title' => 'Original Title']),
            'original_modified' => $article->modified,
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->get(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'resolve', $bouncerRecord->id]);

        $this->assertRedirect(['action' => 'view', $bouncerRecord->id]);
        $this->assertFlashMessage('No changes detected since draft creation. You can proceed with normal approval.');
    }

    /**
     * Test resolve method shows conflict interface
     *
     * @return void
     */
    public function testResolveShowsConflictInterface(): void
    {
        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article);
        $originalModified = $article->modified;

        // Update the article (simulate concurrent edit)
        $article->title = 'Current Title';
        $article->modified = new DateTime('+1 hour');
        $this->Articles->save($article);

        // Create a pending edit bouncer record with old original_modified
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $article->id,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Proposed Title', 'body' => 'Original Body']),
            'original_data' => json_encode(['title' => 'Original Title', 'body' => 'Original Body']),
            'original_modified' => $originalModified,
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->get(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'resolve', $bouncerRecord->id]);

        $this->assertResponseOk();
    }

    /**
     * Test resolve method POST saves merged data
     *
     * @return void
     */
    public function testResolvePostSavesMergedData(): void
    {
        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article);
        $originalModified = $article->modified;

        // Update the article (simulate concurrent edit)
        $article->title = 'Current Title';
        $article->modified = new DateTime('+1 hour');
        $this->Articles->save($article);

        // Create a pending edit bouncer record with old original_modified
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $article->id,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Proposed Title', 'body' => 'Original Body']),
            'original_data' => json_encode(['title' => 'Original Title', 'body' => 'Original Body']),
            'original_modified' => $originalModified,
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->post(
            ['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'resolve', $bouncerRecord->id],
            [
                'merged' => [
                    'title' => 'Merged Title',
                    'body' => 'Original Body',
                ],
            ],
        );

        $this->assertRedirect(['action' => 'view', $bouncerRecord->id]);
        $this->assertFlashMessage('Conflict resolved. Ready for final approval.');

        // Verify the bouncer record was updated with merged data
        $updatedRecord = $this->BouncerRecords->get($bouncerRecord->id);
        $data = $updatedRecord->getData();
        $this->assertEquals('Merged Title', $data['title']);
    }

    /**
     * Test approve method requires POST
     *
     * @return void
     */
    public function testApproveRequiresPost(): void
    {
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => null,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Test', 'body' => 'Body', 'user_id' => 1]),
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->get(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'approve', $bouncerRecord->id]);

        $this->assertResponseCode(405);
    }

    /**
     * Test approve method for already processed record
     *
     * @return void
     */
    public function testApproveAlreadyProcessed(): void
    {
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => 1,
            'user_id' => 1,
            'status' => 'approved',
            'data' => json_encode(['title' => 'Test']),
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->post(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'approve', $bouncerRecord->id]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('This record has already been processed.');
    }

    /**
     * Test approve method creates new record
     *
     * @return void
     */
    public function testApproveCreatesNewRecord(): void
    {
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => null,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'New Article', 'body' => 'Content', 'user_id' => 1]),
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->post(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'approve', $bouncerRecord->id]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('Changes have been approved and published.');

        // Verify article was created
        $article = $this->Articles->find()->where(['title' => 'New Article'])->first();
        $this->assertNotNull($article);

        // Verify bouncer record was updated
        $updatedRecord = $this->BouncerRecords->get($bouncerRecord->id);
        $this->assertEquals('approved', $updatedRecord->status);
        $this->assertNotNull($updatedRecord->primary_key);
    }

    /**
     * Test approve method applies edit
     *
     * @return void
     */
    public function testApproveAppliesEdit(): void
    {
        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article);

        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $article->id,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Updated Title', 'body' => 'Updated Body', 'user_id' => 1]),
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->post(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'approve', $bouncerRecord->id]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('Changes have been approved and published.');

        // Verify article was updated
        $updatedArticle = $this->Articles->get($article->id);
        $this->assertEquals('Updated Title', $updatedArticle->title);
        $this->assertEquals('Updated Body', $updatedArticle->body);
    }

    /**
     * Test reject method requires POST
     *
     * @return void
     */
    public function testRejectRequiresPost(): void
    {
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => null,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Test']),
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->get(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'reject', $bouncerRecord->id]);

        $this->assertResponseCode(405);
    }

    /**
     * Test reject method for already processed record
     *
     * @return void
     */
    public function testRejectAlreadyProcessed(): void
    {
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => 1,
            'user_id' => 1,
            'status' => 'rejected',
            'data' => json_encode(['title' => 'Test']),
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->post(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'reject', $bouncerRecord->id]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('This record has already been processed.');
    }

    /**
     * Test reject method works
     *
     * @return void
     */
    public function testRejectWorks(): void
    {
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => null,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Test']),
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->post(
            ['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'reject', $bouncerRecord->id],
            ['reason' => 'Not acceptable'],
        );

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('Changes have been rejected.');

        $updatedRecord = $this->BouncerRecords->get($bouncerRecord->id);
        $this->assertEquals('rejected', $updatedRecord->status);
        $this->assertEquals('Not acceptable', $updatedRecord->reason);
    }

    /**
     * Test reopen method requires POST
     *
     * @return void
     */
    public function testReopenRequiresPost(): void
    {
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => null,
            'user_id' => 1,
            'status' => 'rejected',
            'data' => json_encode(['title' => 'Test']),
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->get(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'reopen', $bouncerRecord->id]);

        $this->assertResponseCode(405);
    }

    /**
     * Test reopen method only works on rejected records
     *
     * @return void
     */
    public function testReopenOnlyRejected(): void
    {
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => null,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Test']),
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->post(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'reopen', $bouncerRecord->id]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('Only rejected records can be reopened.');
    }

    /**
     * Test reopen method works
     *
     * @return void
     */
    public function testReopenWorks(): void
    {
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => null,
            'user_id' => 1,
            'status' => 'rejected',
            'reviewer_id' => 2,
            'reviewed' => new DateTime(),
            'reason' => 'Not acceptable',
            'data' => json_encode(['title' => 'Test']),
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->post(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'reopen', $bouncerRecord->id]);

        $this->assertRedirect(['action' => 'view', $bouncerRecord->id]);
        $this->assertFlashMessage('Record has been reopened for review.');

        $updatedRecord = $this->BouncerRecords->get($bouncerRecord->id);
        $this->assertEquals('pending', $updatedRecord->status);
        $this->assertNull($updatedRecord->reviewer_id);
        $this->assertNull($updatedRecord->reviewed);
        $this->assertNull($updatedRecord->reason);
    }

    /**
     * Test delete method requires POST
     *
     * @return void
     */
    public function testDeleteRequiresPost(): void
    {
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => null,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Test']),
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->get(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'delete', $bouncerRecord->id]);

        $this->assertResponseCode(405);
    }

    /**
     * Test delete method works
     *
     * @return void
     */
    public function testDeleteWorks(): void
    {
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => null,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Test']),
        ]);
        $this->BouncerRecords->save($bouncerRecord);
        $id = $bouncerRecord->id;

        $this->post(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'delete', $id]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('Bouncer record has been deleted.');

        $this->assertFalse($this->BouncerRecords->exists(['id' => $id]));
    }

    /**
     * Test approve auto-merges stale record with non-overlapping changes
     *
     * @return void
     */
    public function testApproveAutoMergesStaleRecordNonOverlapping(): void
    {
        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article);
        $originalModified = $article->modified;

        // Simulate concurrent edit - change body only
        $article->body = 'Current Body Changed';
        $article->modified = new DateTime('+1 hour');
        $this->Articles->save($article);

        // Create bouncer record that changes title only (non-overlapping)
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $article->id,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Proposed Title', 'body' => 'Original Body', 'user_id' => 1]),
            'original_data' => json_encode(['title' => 'Original Title', 'body' => 'Original Body', 'user_id' => 1]),
            'original_modified' => $originalModified,
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->post(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'approve', $bouncerRecord->id]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('Changes have been approved and published.');

        // Verify article has BOTH changes - proposed title AND current body
        $updatedArticle = $this->Articles->get($article->id);
        $this->assertEquals('Proposed Title', $updatedArticle->title);
        $this->assertEquals('Current Body Changed', $updatedArticle->body);
    }

    /**
     * Test approve auto-merges stale record with string-level non-overlapping changes
     *
     * @return void
     */
    public function testApproveAutoMergesStaleRecordStringLevel(): void
    {
        // Create an article with text that will be modified in different places
        $article = $this->Articles->newEntity([
            'title' => 'Hello World Test',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article);
        $originalModified = $article->modified;

        // Concurrent edit - change start of title
        $article->title = 'Hi World Test';
        $article->modified = new DateTime('+1 hour');
        $this->Articles->save($article);

        // Bouncer record changes end of title (non-overlapping within same field)
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $article->id,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Hello World Test!', 'body' => 'Original Body', 'user_id' => 1]),
            'original_data' => json_encode(['title' => 'Hello World Test', 'body' => 'Original Body', 'user_id' => 1]),
            'original_modified' => $originalModified,
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->post(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'approve', $bouncerRecord->id]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('Changes have been approved and published.');

        // Verify article has merged title: "Hi World Test!"
        $updatedArticle = $this->Articles->get($article->id);
        $this->assertEquals('Hi World Test!', $updatedArticle->title);
    }

    /**
     * Test approve redirects to resolve when there are conflicts
     *
     * @return void
     */
    public function testApproveRedirectsToResolveOnConflict(): void
    {
        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article);
        $originalModified = $article->modified;

        // Concurrent edit - change title
        $article->title = 'Current Title';
        $article->modified = new DateTime('+1 hour');
        $this->Articles->save($article);

        // Bouncer record also changes title (overlapping - conflict!)
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $article->id,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Proposed Title', 'body' => 'Original Body', 'user_id' => 1]),
            'original_data' => json_encode(['title' => 'Original Title', 'body' => 'Original Body', 'user_id' => 1]),
            'original_modified' => $originalModified,
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->post(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'approve', $bouncerRecord->id]);

        $this->assertRedirect(['action' => 'resolve', $bouncerRecord->id]);
        $this->assertFlashMessage('This record has unresolved conflicts. Please resolve them first.');

        // Verify article was NOT changed
        $unchangedArticle = $this->Articles->get($article->id);
        $this->assertEquals('Current Title', $unchangedArticle->title);

        // Verify bouncer record is still pending
        $unchangedRecord = $this->BouncerRecords->get($bouncerRecord->id);
        $this->assertEquals('pending', $unchangedRecord->status);
    }

    /**
     * Test approve works normally for non-stale records
     *
     * @return void
     */
    public function testApproveNonStaleRecordWorksNormally(): void
    {
        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article);

        // Create bouncer record with same original_modified (not stale)
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $article->id,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Proposed Title', 'body' => 'Original Body', 'user_id' => 1]),
            'original_data' => json_encode(['title' => 'Original Title', 'body' => 'Original Body', 'user_id' => 1]),
            'original_modified' => $article->modified,
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->post(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'approve', $bouncerRecord->id]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('Changes have been approved and published.');

        $updatedArticle = $this->Articles->get($article->id);
        $this->assertEquals('Proposed Title', $updatedArticle->title);
    }

    /**
     * Test approve works for records without original_modified (legacy)
     *
     * @return void
     */
    public function testApproveLegacyRecordWithoutOriginalModified(): void
    {
        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article);

        // Create bouncer record WITHOUT original_modified (legacy record)
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $article->id,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Proposed Title', 'body' => 'Original Body', 'user_id' => 1]),
            'original_data' => json_encode(['title' => 'Original Title', 'body' => 'Original Body', 'user_id' => 1]),
            'original_modified' => null,
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->post(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'approve', $bouncerRecord->id]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('Changes have been approved and published.');

        $updatedArticle = $this->Articles->get($article->id);
        $this->assertEquals('Proposed Title', $updatedArticle->title);
    }

    /**
     * Test approve preserves fields not in proposal when auto-merging
     *
     * @return void
     */
    public function testApprovePreservesUnproposedFieldsOnMerge(): void
    {
        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article);
        $originalModified = $article->modified;

        // Concurrent edit - change body
        $article->body = 'Current Body Changed';
        $article->modified = new DateTime('+1 hour');
        $this->Articles->save($article);

        // Bouncer record only proposes title change (body not in proposal)
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $article->id,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Proposed Title', 'user_id' => 1]),
            'original_data' => json_encode(['title' => 'Original Title', 'user_id' => 1]),
            'original_modified' => $originalModified,
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->post(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'approve', $bouncerRecord->id]);

        $this->assertRedirect(['action' => 'index']);

        // Body should remain as current (not reverted to original)
        $updatedArticle = $this->Articles->get($article->id);
        $this->assertEquals('Proposed Title', $updatedArticle->title);
        $this->assertEquals('Current Body Changed', $updatedArticle->body);
    }

    /**
     * Test resolve shows auto-merged fields separately from conflicts
     *
     * @return void
     */
    public function testResolveShowsAutoMergedAndConflictsSeparately(): void
    {
        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article);
        $originalModified = $article->modified;

        // Concurrent edit - change both title and body
        $article->title = 'Current Title';
        $article->body = 'Current Body';
        $article->modified = new DateTime('+1 hour');
        $this->Articles->save($article);

        // Bouncer record changes title (conflict) but not body
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $article->id,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Proposed Title', 'body' => 'Original Body', 'user_id' => 1]),
            'original_data' => json_encode(['title' => 'Original Title', 'body' => 'Original Body', 'user_id' => 1]),
            'original_modified' => $originalModified,
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->get(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'resolve', $bouncerRecord->id]);

        $this->assertResponseOk();
        $this->assertResponseContains('CONFLICT');
        $this->assertResponseContains('title');
    }

    /**
     * Test view page shows merged diff for stale records
     *
     * @return void
     */
    public function testViewShowsMergedDiffForStaleRecords(): void
    {
        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Hello World',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article);
        $originalModified = $article->modified;

        // Concurrent edit - change start
        $article->title = 'Hi World';
        $article->modified = new DateTime('+1 hour');
        $this->Articles->save($article);

        // Bouncer record changes end (non-overlapping)
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $article->id,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Hello World!', 'body' => 'Original Body', 'user_id' => 1]),
            'original_data' => json_encode(['title' => 'Hello World', 'body' => 'Original Body', 'user_id' => 1]),
            'original_modified' => $originalModified,
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->get(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'view', $bouncerRecord->id]);

        $this->assertResponseOk();
        // Should show the merged result "Hi World!" in the diff
        $this->assertResponseContains('Hi World!');
    }

    /**
     * Test resolve post only includes proposed fields in merged data
     *
     * @return void
     */
    public function testResolvePostOnlyIncludesProposedFields(): void
    {
        // Create an article
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original Body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article);
        $originalModified = $article->modified;

        // Concurrent edit
        $article->title = 'Current Title';
        $article->modified = new DateTime('+1 hour');
        $this->Articles->save($article);

        // Bouncer record with conflict
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $article->id,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Proposed Title', 'user_id' => 1]),
            'original_data' => json_encode(['title' => 'Original Title', 'user_id' => 1]),
            'original_modified' => $originalModified,
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        // Post merged data - only title should be saved, not body
        $this->post(
            ['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'resolve', $bouncerRecord->id],
            [
                'merged' => [
                    'title' => 'Resolved Title',
                    'user_id' => 1,
                ],
            ],
        );

        $this->assertRedirect(['action' => 'view', $bouncerRecord->id]);

        // Verify only proposed fields are in data
        $updatedRecord = $this->BouncerRecords->get($bouncerRecord->id);
        $data = $updatedRecord->getData();
        $this->assertEquals('Resolved Title', $data['title']);
        $this->assertArrayNotHasKey('body', $data);
    }

    /**
     * Test complex 3-way merge scenario with multiple fields
     *
     * @return void
     */
    public function testApproveComplexMergeScenario(): void
    {
        // Create an article with multiple fields
        $article = $this->Articles->newEntity([
            'title' => 'AAAA BBBB CCCC',
            'body' => 'Line 1 here',
            'user_id' => 1,
        ]);
        $this->Articles->save($article);
        $originalModified = $article->modified;

        // Concurrent edit - change middle of title, end of body
        $article->title = 'AAAA XXXX CCCC';
        $article->body = 'Line 1 here - updated';
        $article->modified = new DateTime('+1 hour');
        $this->Articles->save($article);

        // Bouncer proposes changes to end of title, start of body (non-overlapping)
        $bouncerRecord = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => $article->id,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode([
                'title' => 'AAAA BBBB CCCC DDDD',
                'body' => 'New Line 1 here',
                'user_id' => 1,
            ]),
            'original_data' => json_encode([
                'title' => 'AAAA BBBB CCCC',
                'body' => 'Line 1 here',
                'user_id' => 1,
            ]),
            'original_modified' => $originalModified,
        ]);
        $this->BouncerRecords->save($bouncerRecord);

        $this->post(['plugin' => 'Bouncer', 'prefix' => 'Admin', 'controller' => 'Bouncer', 'action' => 'approve', $bouncerRecord->id]);

        $this->assertRedirect(['action' => 'index']);

        // Verify merged result
        $updatedArticle = $this->Articles->get($article->id);
        // Title: current changed middle (XXXX), proposed added end (DDDD)
        $this->assertEquals('AAAA XXXX CCCC DDDD', $updatedArticle->title);
        // Body: proposed changed start (New), current changed end (- updated)
        $this->assertEquals('New Line 1 here - updated', $updatedArticle->body);
    }
}
