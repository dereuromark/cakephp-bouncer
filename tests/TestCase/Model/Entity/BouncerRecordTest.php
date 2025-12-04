<?php

declare(strict_types=1);

namespace Bouncer\Test\TestCase\Model\Entity;

use Bouncer\Model\Entity\BouncerRecord;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;

/**
 * Bouncer\Model\Entity\BouncerRecord Test Case
 */
class BouncerRecordTest extends TestCase
{
    /**
     * Test getData method with valid JSON
     *
     * @return void
     */
    public function testGetDataWithValidJson(): void
    {
        $entity = new BouncerRecord([
            'data' => json_encode(['title' => 'Test', 'body' => 'Content']),
        ]);

        $result = $entity->getData();

        $this->assertIsArray($result);
        $this->assertEquals('Test', $result['title']);
        $this->assertEquals('Content', $result['body']);
    }

    /**
     * Test getData method with empty data
     *
     * @return void
     */
    public function testGetDataWithEmptyData(): void
    {
        $entity = new BouncerRecord([
            'data' => '',
        ]);

        $result = $entity->getData();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test getData method with null data
     *
     * @return void
     */
    public function testGetDataWithNullData(): void
    {
        $entity = new BouncerRecord([]);

        $result = $entity->getData();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test getOriginalData method with valid JSON
     *
     * @return void
     */
    public function testGetOriginalDataWithValidJson(): void
    {
        $entity = new BouncerRecord([
            'original_data' => json_encode(['title' => 'Original', 'body' => 'Original Content']),
        ]);

        $result = $entity->getOriginalData();

        $this->assertIsArray($result);
        $this->assertEquals('Original', $result['title']);
        $this->assertEquals('Original Content', $result['body']);
    }

    /**
     * Test getOriginalData method with empty data
     *
     * @return void
     */
    public function testGetOriginalDataWithEmptyData(): void
    {
        $entity = new BouncerRecord([
            'original_data' => '',
        ]);

        $result = $entity->getOriginalData();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test getOriginalData method with null data
     *
     * @return void
     */
    public function testGetOriginalDataWithNullData(): void
    {
        $entity = new BouncerRecord([]);

        $result = $entity->getOriginalData();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test isNewRecordProposal method
     *
     * @return void
     */
    public function testIsNewRecordProposal(): void
    {
        $newRecord = new BouncerRecord([
            'primary_key' => null,
        ]);

        $editRecord = new BouncerRecord([
            'primary_key' => 1,
        ]);

        $this->assertTrue($newRecord->isNewRecordProposal());
        $this->assertFalse($editRecord->isNewRecordProposal());
    }

    /**
     * Test isEditProposal method
     *
     * @return void
     */
    public function testIsEditProposal(): void
    {
        $newRecord = new BouncerRecord([
            'primary_key' => null,
        ]);

        $editRecord = new BouncerRecord([
            'primary_key' => 1,
        ]);

        $this->assertFalse($newRecord->isEditProposal());
        $this->assertTrue($editRecord->isEditProposal());
    }

    /**
     * Test isPending method
     *
     * @return void
     */
    public function testIsPending(): void
    {
        $pending = new BouncerRecord(['status' => 'pending']);
        $approved = new BouncerRecord(['status' => 'approved']);
        $rejected = new BouncerRecord(['status' => 'rejected']);

        $this->assertTrue($pending->isPending());
        $this->assertFalse($approved->isPending());
        $this->assertFalse($rejected->isPending());
    }

    /**
     * Test isApproved method
     *
     * @return void
     */
    public function testIsApproved(): void
    {
        $pending = new BouncerRecord(['status' => 'pending']);
        $approved = new BouncerRecord(['status' => 'approved']);
        $rejected = new BouncerRecord(['status' => 'rejected']);

        $this->assertFalse($pending->isApproved());
        $this->assertTrue($approved->isApproved());
        $this->assertFalse($rejected->isApproved());
    }

    /**
     * Test isRejected method
     *
     * @return void
     */
    public function testIsRejected(): void
    {
        $pending = new BouncerRecord(['status' => 'pending']);
        $approved = new BouncerRecord(['status' => 'approved']);
        $rejected = new BouncerRecord(['status' => 'rejected']);

        $this->assertFalse($pending->isRejected());
        $this->assertFalse($approved->isRejected());
        $this->assertTrue($rejected->isRejected());
    }

    /**
     * Test isDeleteProposal method
     *
     * @return void
     */
    public function testIsDeleteProposal(): void
    {
        $deleteProposal = new BouncerRecord([
            'data' => json_encode(['_delete' => true]),
        ]);

        $editProposal = new BouncerRecord([
            'data' => json_encode(['title' => 'Updated']),
        ]);

        $deleteProposalFalse = new BouncerRecord([
            'data' => json_encode(['_delete' => false]),
        ]);

        $this->assertTrue($deleteProposal->isDeleteProposal());
        $this->assertFalse($editProposal->isDeleteProposal());
        $this->assertFalse($deleteProposalFalse->isDeleteProposal());
    }

    /**
     * Test hasOriginalModified method
     *
     * @return void
     */
    public function testHasOriginalModified(): void
    {
        $withOriginalModified = new BouncerRecord([
            'original_modified' => new DateTime(),
        ]);

        $withoutOriginalModified = new BouncerRecord([]);

        $this->assertTrue($withOriginalModified->hasOriginalModified());
        $this->assertFalse($withoutOriginalModified->hasOriginalModified());
    }

    /**
     * Test canDetectStaleness method
     *
     * @return void
     */
    public function testCanDetectStaleness(): void
    {
        $withOriginalModified = new BouncerRecord([
            'original_modified' => new DateTime(),
        ]);

        $withNullOriginalModified = new BouncerRecord([
            'original_modified' => null,
        ]);

        $withoutField = new BouncerRecord([]);

        $this->assertTrue($withOriginalModified->canDetectStaleness());
        $this->assertFalse($withNullOriginalModified->canDetectStaleness());
        $this->assertFalse($withoutField->canDetectStaleness());
    }

    /**
     * Test entity accessible fields
     *
     * @return void
     */
    public function testAccessibleFields(): void
    {
        $entity = new BouncerRecord([
            'source' => 'Articles',
            'primary_key' => 1,
            'user_id' => 1,
            'reviewer_id' => 2,
            'status' => 'pending',
            'data' => '{}',
            'original_data' => '{}',
            'note' => 'Test note',
            'original_modified' => new DateTime(),
            'reason' => 'Test reason',
            'reviewed' => new DateTime(),
            'created' => new DateTime(),
            'modified' => new DateTime(),
        ]);

        $this->assertEquals('Articles', $entity->source);
        $this->assertEquals(1, $entity->primary_key);
        $this->assertEquals(1, $entity->user_id);
        $this->assertEquals(2, $entity->reviewer_id);
        $this->assertEquals('pending', $entity->status);
        $this->assertEquals('{}', $entity->data);
        $this->assertEquals('{}', $entity->original_data);
        $this->assertEquals('Test note', $entity->note);
        $this->assertInstanceOf(DateTime::class, $entity->original_modified);
        $this->assertEquals('Test reason', $entity->reason);
        $this->assertInstanceOf(DateTime::class, $entity->reviewed);
    }
}
