<?php

declare(strict_types=1);

namespace Bouncer\Test\TestCase\Model\Behavior;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;
use Cake\Utility\Text;
use InvalidArgumentException;

/**
 * Tests for UUID type mismatch scenarios
 *
 * This tests the scenario where the source table uses UUID for primary_key
 * and user_id, but the bouncer_records table uses integer fields.
 *
 * When there's a type mismatch, CakePHP throws an InvalidArgumentException
 * which is the expected behavior - it clearly indicates a configuration problem.
 *
 * To fix this, users must copy and adjust the migration to use UUID types
 * for the bouncer_records table fields (primary_key, user_id, reviewer_id).
 */
class BouncerBehaviorUuidTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'plugin.Bouncer.BouncerRecords',
        'plugin.Bouncer.UuidArticles',
    ];

    /**
     * @var \TestApp\Model\Table\UuidArticlesTable
     */
    protected $UuidArticles;

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

        $this->UuidArticles = $this->fetchTable('TestApp.UuidArticles');
        $this->BouncerRecords = $this->fetchTable('Bouncer.BouncerRecords');
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->UuidArticles, $this->BouncerRecords);

        parent::tearDown();
    }

    /**
     * Test that UUID user_id with integer bouncer_records.user_id throws exception
     *
     * When the bouncer_records table has integer fields but UUIDs are passed,
     * CakePHP will throw an InvalidArgumentException. This is the expected
     * behavior - it clearly indicates a configuration problem.
     *
     * Solution: Copy the migration to app and change field types to UUID.
     */
    public function testUuidUserIdWithIntegerBouncerFieldThrowsException(): void
    {
        $this->UuidArticles->addBehavior('Bouncer.Bouncer', [
            'userField' => 'user_id',
        ]);

        $uuid = Text::uuid();
        $userUuid = Text::uuid();

        $article = $this->UuidArticles->newEntity([
            'id' => $uuid,
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => $userUuid,
        ]);

        // CakePHP throws InvalidArgumentException when trying to convert UUID to int
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot convert value');

        $this->UuidArticles->save($article, ['bouncerUserId' => $userUuid]);
    }

    /**
     * Test that edit with UUID primary key and integer bouncer_records.primary_key throws exception
     */
    public function testUuidPrimaryKeyWithIntegerBouncerFieldThrowsException(): void
    {
        // First create an article bypassing bouncer
        $uuid = Text::uuid();
        $userUuid = Text::uuid();

        $article = $this->UuidArticles->newEntity([
            'id' => $uuid,
            'title' => 'Original Title',
            'body' => 'Original body',
            'user_id' => $userUuid,
        ]);
        $this->UuidArticles->save($article, ['bypassBouncer' => true]);

        // Now add bouncer behavior and try to edit
        $this->UuidArticles->addBehavior('Bouncer.Bouncer', [
            'userField' => 'user_id',
        ]);

        $article = $this->UuidArticles->get($uuid);
        $article->title = 'Updated Title';

        // CakePHP throws InvalidArgumentException when trying to convert UUID to int
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot convert value');

        $this->UuidArticles->save($article, ['bouncerUserId' => $userUuid]);
    }
}
