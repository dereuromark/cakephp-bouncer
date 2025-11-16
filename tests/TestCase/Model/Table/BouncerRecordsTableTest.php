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
