<?php

declare(strict_types=1);

namespace Bouncer\Test\TestCase\Controller\Admin;

use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Bouncer\Controller\Admin\BouncerController Test Case
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
}
