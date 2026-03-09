<?php

declare(strict_types=1);

namespace Bouncer\Test\TestCase\View\Helper;

use Bouncer\Model\Entity\BouncerRecord;
use Bouncer\View\Helper\BouncerHelper;
use Cake\Core\Configure;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;
use Cake\View\View;

class BouncerHelperTest extends TestCase
{
    protected BouncerHelper $helper;

    public function setUp(): void
    {
        parent::setUp();

        $view = new View();
        $this->helper = new BouncerHelper($view);
    }

    public function testCalculateDiffsWithEditProposal(): void
    {
        $bouncerRecord = new BouncerRecord([
            'id' => 1,
            'source' => 'Articles',
            'primary_key' => 42,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'New Title', 'content' => 'New Content']),
            'original_data' => json_encode(['title' => 'Old Title', 'content' => 'Old Content']),
        ]);

        $currentRecord = new Entity([
            'id' => 42,
            'title' => 'Old Title',
            'content' => 'Old Content',
        ]);

        $diffs = $this->helper->calculateDiffs($bouncerRecord, $currentRecord);

        $this->assertArrayHasKey('title', $diffs);
        $this->assertArrayHasKey('content', $diffs);
        $this->assertEquals('Old Title', $diffs['title']['baseStr']);
        $this->assertEquals('New Title', $diffs['title']['proposedStr']);
    }

    public function testCalculateDiffsWithNewRecord(): void
    {
        $bouncerRecord = new BouncerRecord([
            'id' => 1,
            'source' => 'Articles',
            'primary_key' => null,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'New Article']),
        ]);

        $diffs = $this->helper->calculateDiffs($bouncerRecord, null);

        $this->assertEmpty($diffs);
    }

    public function testCalculateDiffsSkipsSystemFields(): void
    {
        $bouncerRecord = new BouncerRecord([
            'id' => 1,
            'source' => 'Articles',
            'primary_key' => 42,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode([
                'title' => 'New Title',
                'created' => '2024-01-01',
                'modified' => '2024-01-02',
                'id' => 42,
                '_hidden' => 'secret',
            ]),
        ]);

        $currentRecord = new Entity([
            'id' => 42,
            'title' => 'Old Title',
            'created' => '2023-01-01',
            'modified' => '2023-01-02',
            '_hidden' => 'old_secret',
        ]);

        $diffs = $this->helper->calculateDiffs($bouncerRecord, $currentRecord);

        $this->assertArrayHasKey('title', $diffs);
        $this->assertArrayNotHasKey('created', $diffs);
        $this->assertArrayNotHasKey('modified', $diffs);
        $this->assertArrayNotHasKey('id', $diffs);
        $this->assertArrayNotHasKey('_hidden', $diffs);
    }

    public function testDiffInlineReturnsHtml(): void
    {
        $diffs = [
            'title' => [
                'baseStr' => 'Old',
                'proposedStr' => 'New',
                'isLongText' => false,
                'inline' => null,
                'sideBySide' => null,
            ],
        ];

        $result = $this->helper->diffInline($diffs);

        $this->assertStringContainsString('card', $result);
        $this->assertStringContainsString('title', $result);
        $this->assertStringContainsString('Old', $result);
        $this->assertStringContainsString('New', $result);
    }

    public function testDiffInlineWithEmptyDiffs(): void
    {
        $result = $this->helper->diffInline([]);

        $this->assertStringContainsString('No changes detected', $result);
    }

    public function testDiffInlineWithLongText(): void
    {
        $diffs = [
            'description' => [
                'baseStr' => 'Old text',
                'proposedStr' => 'New text',
                'isLongText' => true,
                'inline' => '<div class="diff-rendered">Inline diff content</div>',
                'sideBySide' => '<div class="diff-rendered">Side by side content</div>',
            ],
        ];

        $result = $this->helper->diffInline($diffs);

        $this->assertStringContainsString('card', $result);
        $this->assertStringContainsString('description', $result);
        $this->assertStringContainsString('Inline diff content', $result);
    }

    public function testDiffSideBySideReturnsHtml(): void
    {
        $diffs = [
            'content' => [
                'baseStr' => 'Old content',
                'proposedStr' => 'New content',
                'isLongText' => false,
                'inline' => null,
                'sideBySide' => null,
            ],
        ];

        $result = $this->helper->diffSideBySide($diffs);

        $this->assertStringContainsString('card', $result);
        $this->assertStringContainsString('content', $result);
    }

    public function testDiffSideBySideWithEmptyDiffs(): void
    {
        $result = $this->helper->diffSideBySide([]);

        $this->assertStringContainsString('No changes detected', $result);
    }

    public function testDiffSideBySideWithLongText(): void
    {
        $diffs = [
            'body' => [
                'baseStr' => 'Old body text',
                'proposedStr' => 'New body text',
                'isLongText' => true,
                'inline' => '<div class="diff-inline">Inline</div>',
                'sideBySide' => '<div class="diff-side">Side by side rendered</div>',
            ],
        ];

        $result = $this->helper->diffSideBySide($diffs);

        $this->assertStringContainsString('card', $result);
        $this->assertStringContainsString('body', $result);
        $this->assertStringContainsString('Side by side rendered', $result);
    }

    public function testCalculateDiffsWithLongText(): void
    {
        $longText = str_repeat("This is a line of text.\n", 10);
        $bouncerRecord = new BouncerRecord([
            'id' => 1,
            'source' => 'Articles',
            'primary_key' => 42,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['body' => $longText . ' Modified']),
            'original_data' => json_encode(['body' => $longText]),
        ]);

        $currentRecord = new Entity([
            'id' => 42,
            'body' => $longText,
        ]);

        $diffs = $this->helper->calculateDiffs($bouncerRecord, $currentRecord);

        $this->assertArrayHasKey('body', $diffs);
        $this->assertTrue($diffs['body']['isLongText']);
        $this->assertNotNull($diffs['body']['inline']);
        $this->assertNotNull($diffs['body']['sideBySide']);
    }

    public function testCalculateDiffsWithApprovedRecord(): void
    {
        $bouncerRecord = new BouncerRecord([
            'id' => 1,
            'source' => 'Articles',
            'primary_key' => 42,
            'user_id' => 1,
            'status' => 'approved',
            'data' => json_encode(['title' => 'New Title']),
            'original_data' => json_encode(['title' => 'Old Title']),
        ]);

        // Current record might have changed, but we compare against original_data
        $currentRecord = new Entity([
            'id' => 42,
            'title' => 'Different Title',
        ]);

        $diffs = $this->helper->calculateDiffs($bouncerRecord, $currentRecord);

        $this->assertArrayHasKey('title', $diffs);
        $this->assertEquals('Old Title', $diffs['title']['baseStr']);
        $this->assertEquals('New Title', $diffs['title']['proposedStr']);
    }

    public function testNewRecordTable(): void
    {
        $bouncerRecord = new BouncerRecord([
            'id' => 1,
            'source' => 'Articles',
            'primary_key' => null,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'New Article', 'body' => 'Content here']),
        ]);

        $result = $this->helper->newRecordTable($bouncerRecord);

        $this->assertStringContainsString('<table', $result);
        $this->assertStringContainsString('title', $result);
        $this->assertStringContainsString('New Article', $result);
        $this->assertStringContainsString('body', $result);
    }

    public function testRawJsonDetails(): void
    {
        $bouncerRecord = new BouncerRecord([
            'id' => 1,
            'source' => 'Articles',
            'primary_key' => null,
            'user_id' => 1,
            'status' => 'pending',
            'data' => json_encode(['title' => 'Test']),
        ]);

        $result = $this->helper->rawJsonDetails($bouncerRecord);

        $this->assertStringContainsString('<details', $result);
        $this->assertStringContainsString('Raw JSON Data', $result);
        $this->assertStringContainsString('<pre', $result);
    }

    public function testStatusBadge(): void
    {
        $this->assertStringContainsString('bg-warning', $this->helper->statusBadge('pending'));
        $this->assertStringContainsString('bg-success', $this->helper->statusBadge('approved'));
        $this->assertStringContainsString('bg-danger', $this->helper->statusBadge('rejected'));
        $this->assertStringContainsString('bg-secondary', $this->helper->statusBadge('unknown'));
    }

    public function testRecordTypeBadge(): void
    {
        $newRecord = new BouncerRecord([
            'primary_key' => null,
        ]);
        $result = $this->helper->recordTypeBadge($newRecord);
        $this->assertStringContainsString('New Record', $result);
        $this->assertStringContainsString('bg-success', $result);

        $editRecord = new BouncerRecord([
            'source' => 'Articles',
            'primary_key' => 42,
        ]);
        $result = $this->helper->recordTypeBadge($editRecord);
        $this->assertStringContainsString('Edit to Record', $result);
        $this->assertStringContainsString('42', $result);
        $this->assertStringContainsString('bg-info', $result);
    }

    public function testDiffStyles(): void
    {
        $result = $this->helper->diffStyles();

        $this->assertStringContainsString('<style>', $result);
        $this->assertStringContainsString('.diff-wrapper', $result);
        $this->assertStringContainsString('</style>', $result);
    }

    public function testDiffToggleButtons(): void
    {
        $result = $this->helper->diffToggleButtons();

        $this->assertStringContainsString('btn-group', $result);
        $this->assertStringContainsString('btn-inline-diff', $result);
        $this->assertStringContainsString('btn-side-diff', $result);
        $this->assertStringContainsString('Inline', $result);
        $this->assertStringContainsString('Side-by-side', $result);
    }

    public function testDiffToggleScript(): void
    {
        $result = $this->helper->diffToggleScript();

        $this->assertStringContainsString('<script>', $result);
        $this->assertStringContainsString('addEventListener', $result);
        $this->assertStringContainsString('</script>', $result);
    }

    public function testFormatValueWithNull(): void
    {
        $result = $this->helper->formatValue(null);

        $this->assertStringContainsString('null', $result);
        $this->assertStringContainsString('text-muted', $result);
    }

    public function testFormatValueWithBool(): void
    {
        $trueResult = $this->helper->formatValue(true);
        $falseResult = $this->helper->formatValue(false);

        $this->assertStringContainsString('true', $trueResult);
        $this->assertStringContainsString('bg-success', $trueResult);
        $this->assertStringContainsString('false', $falseResult);
        $this->assertStringContainsString('bg-secondary', $falseResult);
    }

    public function testFormatValueWithArray(): void
    {
        $result = $this->helper->formatValue(['key' => 'value']);

        $this->assertStringContainsString('<pre', $result);
        $this->assertStringContainsString('<code>', $result);
        $this->assertStringContainsString('key', $result);
    }

    public function testFormatValueEscapesHtml(): void
    {
        $result = $this->helper->formatValue('<script>alert("xss")</script>');

        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringNotContainsString('<script>alert', $result);
    }

    public function testFormatValueWithLongString(): void
    {
        $longString = str_repeat('a', 150);
        $result = $this->helper->formatValue($longString);

        $this->assertStringContainsString('text-break', $result);
        $this->assertStringContainsString($longString, $result);
    }

    public function testFormatUserWithNull(): void
    {
        $result = $this->helper->formatUser(null);

        $this->assertStringContainsString('N/A', $result);
        $this->assertStringContainsString('text-muted', $result);
    }

    public function testFormatUserWithEmptyString(): void
    {
        $result = $this->helper->formatUser('');

        $this->assertStringContainsString('N/A', $result);
    }

    public function testFormatUserWithIdOnly(): void
    {
        $result = $this->helper->formatUser(42);

        $this->assertStringContainsString('User #42', $result);
    }

    public function testFormatUserWithDisplayName(): void
    {
        $result = $this->helper->formatUser(42, 'John Doe');

        $this->assertStringContainsString('John Doe', $result);
        $this->assertStringNotContainsString('User #42', $result);
    }

    public function testFormatUserWithStringLinkConfig(): void
    {
        Configure::write('Bouncer.linkUser', '/admin/users/view/{user}');

        $result = $this->helper->formatUser(42, 'John Doe');

        $this->assertStringContainsString('href="/admin/users/view/42"', $result);
        $this->assertStringContainsString('John Doe', $result);

        Configure::delete('Bouncer.linkUser');
    }

    public function testFormatUserWithStringIdLinkConfig(): void
    {
        Configure::write('Bouncer.linkUser', '/users/{user}');

        $result = $this->helper->formatUser('123');

        $this->assertStringContainsString('href="/users/123"', $result);
        $this->assertStringContainsString('User #123', $result);

        Configure::delete('Bouncer.linkUser');
    }

    public function testFormatUserWithCallableLinkConfig(): void
    {
        Configure::write('Bouncer.linkUser', function ($userId) {
            return '/custom/user/' . $userId;
        });

        $result = $this->helper->formatUser(99);

        $this->assertStringContainsString('href="/custom/user/99"', $result);

        Configure::delete('Bouncer.linkUser');
    }

    public function testFormatRecordWithNullPrimaryKey(): void
    {
        $result = $this->helper->formatRecord('Articles', null);

        $this->assertStringContainsString('New', $result);
        $this->assertStringContainsString('badge', $result);
        $this->assertStringContainsString('bg-success', $result);
    }

    public function testFormatRecordWithPrimaryKeyNoLink(): void
    {
        $result = $this->helper->formatRecord('Articles', 42);

        $this->assertStringContainsString('42', $result);
        $this->assertStringNotContainsString('href=', $result);
    }

    public function testFormatRecordWithStringLinkConfig(): void
    {
        Configure::write('Bouncer.linkRecord', '/admin/{table}/view/{primary_key}');

        $result = $this->helper->formatRecord('Articles', '42');

        $this->assertStringContainsString('href="/admin/Articles/view/42"', $result);
        $this->assertStringContainsString('42', $result);

        Configure::delete('Bouncer.linkRecord');
    }

    public function testFormatRecordWithPluginSource(): void
    {
        Configure::write('Bouncer.linkRecord', '/{plugin}/{table}/view/{primary_key}');

        $result = $this->helper->formatRecord('Community.Stories', '99');

        $this->assertStringContainsString('href="/Community/Stories/view/99"', $result);

        Configure::delete('Bouncer.linkRecord');
    }

    public function testFormatRecordWithCallableLinkConfig(): void
    {
        Configure::write('Bouncer.linkRecord', function ($source, $primaryKey, $plugin, $tableName) {
            return '/records/' . $tableName . '/' . $primaryKey;
        });

        $result = $this->helper->formatRecord('Articles', '77');

        $this->assertStringContainsString('href="/records/Articles/77"', $result);

        Configure::delete('Bouncer.linkRecord');
    }
}
