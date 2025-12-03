<?php

declare(strict_types=1);

namespace Bouncer\Test\TestCase\Model\Behavior;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * Tests for bouncer record validation failure scenarios
 *
 * This tests the scenario where the bouncer record fails validation,
 * ensuring the original save does NOT proceed.
 */
class BouncerBehaviorValidationTest extends TestCase
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
     * Test that when bouncer record validation fails, the original save does NOT proceed
     *
     * This test adds an artificial validation rule to BouncerRecords that will always fail,
     * simulating a scenario like UUID/integer type mismatch at validation level.
     *
     * BUG: Currently when bouncer record save fails, the original save proceeds!
     */
    public function testBouncerValidationFailureAllowsOriginalSaveThrough(): void
    {
        // Add artificial validation rule that always fails
        $validator = $this->BouncerRecords->getValidator('default');
        $validator->add('user_id', 'alwaysFail', [
            'rule' => function ($value) {
                // Simulate validation failure (e.g., UUID passed to integer field)
                return false;
            },
            'message' => 'Simulated validation failure',
        ]);

        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'userField' => 'user_id',
        ]);

        $article = $this->Articles->newEntity([
            'title' => 'Test Article',
            'body' => 'Test body',
            'user_id' => 1,
        ]);

        // Try to save - bouncer record validation will fail
        $result = $this->Articles->save($article, ['bouncerUserId' => 1]);

        // BUG: The save should be blocked (return false) because bouncer couldn't create record
        // But currently it proceeds and saves the article directly!
        $this->assertFalse($result, 'Save should fail when bouncer record validation fails');

        // Verify no bouncer record was created
        $bouncerCount = $this->BouncerRecords->find()->count();
        $this->assertEquals(0, $bouncerCount, 'No bouncer record should exist');

        // BUG: Article should NOT exist because save should have been blocked
        $articleCount = $this->Articles->find()->count();
        $this->assertEquals(0, $articleCount, 'Article should not be saved when bouncer validation fails');
    }

    /**
     * Test edit scenario: bouncer validation failure should not update original record
     */
    public function testBouncerValidationFailureOnEditDoesNotUpdateOriginal(): void
    {
        // First create an article bypassing bouncer
        $article = $this->Articles->newEntity([
            'title' => 'Original Title',
            'body' => 'Original body',
            'user_id' => 1,
        ]);
        $this->Articles->save($article, ['bypassBouncer' => true]);
        $articleId = $article->id;

        // Now add artificial validation rule that always fails
        $validator = $this->BouncerRecords->getValidator('default');
        $validator->add('user_id', 'alwaysFail', [
            'rule' => function ($value) {
                return false;
            },
            'message' => 'Simulated validation failure',
        ]);

        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'userField' => 'user_id',
        ]);

        // Try to edit
        $article = $this->Articles->get($articleId);
        $article->title = 'Updated Title';

        $result = $this->Articles->save($article, ['bouncerUserId' => 1]);

        // BUG: The save should fail because bouncer couldn't create record
        $this->assertFalse($result, 'Edit should fail when bouncer record validation fails');

        // BUG: Article should still have original title
        $article = $this->Articles->get($articleId);
        $this->assertEquals('Original Title', $article->title, 'Article should not be updated when bouncer validation fails');
    }
}
