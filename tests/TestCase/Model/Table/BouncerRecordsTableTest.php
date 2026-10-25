<?php

declare(strict_types=1);

namespace Bouncer\Test\TestCase\Model\Table;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * Bouncer\Model\Table\BouncerRecordsTable Test Case
 */
class BouncerRecordsTableTest extends TestCase
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

        $this->BouncerRecords = $this->fetchTable('Bouncer.BouncerRecords');
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->BouncerRecords);

        parent::tearDown();
    }

    public function testApproveAndReject(): void
    {
        $proposal = $this->BouncerRecords->newEntity([
            'source' => 'TestApp.Articles',
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Approved title', 'body' => 'Body', 'user_id' => 1]),
        ]);
        $this->BouncerRecords->saveOrFail($proposal);
        $result = $this->BouncerRecords->approve($proposal, 12, 'Looks good');
        $this->assertNotFalse($result);
        $this->assertSame('Approved title', $result->get('title'));
        $this->assertSame('approved', $proposal->status);
        $this->assertEquals(12, $proposal->reviewer_id);
        $this->assertFalse($this->BouncerRecords->approve($proposal, 12));
        $this->assertFalse($this->BouncerRecords->reject($proposal, 12, 'Later'));

        $other = $this->BouncerRecords->newEntity([
            'source' => 'TestApp.Articles',
            'user_id' => 1,
            'status' => 'pending',
            'data' => '{}',
        ]);
        $this->BouncerRecords->saveOrFail($other);
        $this->assertFalse($this->BouncerRecords->reject($other, 12, '  '));
        $this->assertTrue($this->BouncerRecords->reject($other, 12, ' No '));
        $this->assertSame('No', $other->reason);
        $this->assertSame('rejected', $other->status);
    }

    public function testApproveDelete(): void
    {
        $articles = $this->fetchTable('TestApp.Articles');
        $article = $articles->newEntity(['title' => 'Delete me', 'body' => 'Body', 'user_id' => 1]);
        $articles->saveOrFail($article);
        $proposal = $this->BouncerRecords->newEntity([
            'source' => 'TestApp.Articles',
            'primary_key' => $article->id,
            'user_id' => 1,
            'status' => 'pending',
            'data' => '{"_delete":true}',
        ]);
        $this->BouncerRecords->saveOrFail($proposal);
        $other = $this->BouncerRecords->newEntity([
            'source' => 'TestApp.Articles',
            'primary_key' => $article->id,
            'user_id' => null,
            'status' => 'pending',
            'data' => '{"title":"Too late"}',
        ]);
        $this->BouncerRecords->saveOrFail($other);

        $this->assertTrue($this->BouncerRecords->approve($proposal, 12));

        $this->assertSame('approved', $this->BouncerRecords->get($proposal->id)->status);
        $this->assertSame(0, $articles->find()->count());
        $this->assertFalse($this->BouncerRecords->exists(['id' => $other->id]));
    }

    /**
     * Test validationDefault method
     */
    public function testValidationDefault(): void
    {
        $data = [
            'source' => 'Articles',
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Test']),
        ];

        $bouncerRecord = $this->BouncerRecords->newEntity($data);
        $this->assertEmpty($bouncerRecord->getErrors());
    }

    /**
     * Test validation fails for invalid status
     */
    public function testValidationInvalidStatus(): void
    {
        $data = [
            'source' => 'Articles',
            'user_id' => 1,
            'status' => 'invalid',
            'data' => json_encode(['title' => 'Test']),
        ];

        $bouncerRecord = $this->BouncerRecords->newEntity($data);
        $this->assertNotEmpty($bouncerRecord->getErrors());
        $this->assertArrayHasKey('status', $bouncerRecord->getErrors());
    }

    /**
     * Test findPendingForRecord method
     */
    public function testFindPendingForRecord(): void
    {
        // Create test records
        $this->BouncerRecords->saveMany([
            $this->BouncerRecords->newEntity([
                'source' => 'Articles',
                'primary_key' => 1,
                'user_id' => 1,
                'status' => 'pending',
                'data' => '{}',
            ]),
            $this->BouncerRecords->newEntity([
                'source' => 'Articles',
                'primary_key' => 1,
                'user_id' => 2,
                'status' => 'pending',
                'data' => '{}',
            ]),
            $this->BouncerRecords->newEntity([
                'source' => 'Articles',
                'primary_key' => 2,
                'user_id' => 1,
                'status' => 'pending',
                'data' => '{}',
            ]),
        ]);

        // Find for user 1, article 1
        $result = $this->BouncerRecords->findPendingForRecord('Articles', 1, 1)->first();
        $this->assertNotNull($result);
        $this->assertEquals(1, $result->user_id);
        $this->assertEquals(1, $result->primary_key);

        // Find all for article 1
        $results = $this->BouncerRecords->findPendingForRecord('Articles', 1)->toArray();
        $this->assertCount(2, $results);
    }

    /**
     * Test findPending method
     */
    public function testFindPending(): void
    {
        // Create test records
        $this->BouncerRecords->saveMany([
            $this->BouncerRecords->newEntity([
                'source' => 'Articles',
                'user_id' => 1,
                'status' => 'pending',
                'data' => '{}',
            ]),
            $this->BouncerRecords->newEntity([
                'source' => 'Articles',
                'user_id' => 1,
                'status' => 'approved',
                'data' => '{}',
            ]),
            $this->BouncerRecords->newEntity([
                'source' => 'Articles',
                'user_id' => 1,
                'status' => 'pending',
                'data' => '{}',
            ]),
        ]);

        $results = $this->BouncerRecords->findPending()->toArray();
        $this->assertCount(2, $results);

        foreach ($results as $result) {
            $this->assertEquals('pending', $result->status);
        }
    }

    /**
     * Test supersedeOthers method
     */
    public function testSupersedeOthers(): void
    {
        // Create test records
        $record1 = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => 1,
            'user_id' => 1,
            'status' => 'pending',
            'data' => '{}',
        ]);
        $this->BouncerRecords->save($record1);

        $record2 = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => 1,
            'user_id' => 2,
            'status' => 'pending',
            'data' => '{}',
        ]);
        $this->BouncerRecords->save($record2);

        $record3 = $this->BouncerRecords->newEntity([
            'source' => 'Articles',
            'primary_key' => 1,
            'user_id' => 3,
            'status' => 'pending',
            'data' => '{}',
        ]);
        $this->BouncerRecords->save($record3);

        // Supersede all except record2
        $count = $this->BouncerRecords->supersedeOthers('Articles', 1, $record2->id);
        $this->assertEquals(2, $count);

        // Verify
        $record1 = $this->BouncerRecords->get($record1->id);
        $this->assertEquals('superseded', $record1->status);

        $record2 = $this->BouncerRecords->get($record2->id);
        $this->assertEquals('pending', $record2->status);

        $record3 = $this->BouncerRecords->get($record3->id);
        $this->assertEquals('superseded', $record3->status);
    }
}
