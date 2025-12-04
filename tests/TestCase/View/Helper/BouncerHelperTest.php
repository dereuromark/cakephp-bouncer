<?php

declare(strict_types=1);

namespace Bouncer\Test\TestCase\View\Helper;

use Bouncer\Model\Entity\BouncerRecord;
use Bouncer\View\Helper\BouncerHelper;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;
use Cake\View\View;

class BouncerHelperTest extends TestCase {

	protected BouncerHelper $helper;

	public function setUp(): void {
		parent::setUp();

		$view = new View();
		$this->helper = new BouncerHelper($view);
	}

	public function testCalculateDiffsWithEditProposal(): void {
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
		$this->assertEquals('Old Title', $diffs['title']['currentStr']);
		$this->assertEquals('New Title', $diffs['title']['proposedStr']);
	}

	public function testCalculateDiffsWithNewRecord(): void {
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

	public function testCalculateDiffsSkipsSystemFields(): void {
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

	public function testDiffInlineReturnsHtml(): void {
		$diffs = [
			'title' => [
				'currentStr' => 'Old',
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

	public function testDiffInlineWithEmptyDiffs(): void {
		$result = $this->helper->diffInline([]);

		$this->assertStringContainsString('No changes detected', $result);
	}

	public function testDiffSideBySideReturnsHtml(): void {
		$diffs = [
			'content' => [
				'currentStr' => 'Old content',
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

	public function testNewRecordTable(): void {
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

	public function testRawJsonDetails(): void {
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

	public function testStatusBadge(): void {
		$this->assertStringContainsString('bg-warning', $this->helper->statusBadge('pending'));
		$this->assertStringContainsString('bg-success', $this->helper->statusBadge('approved'));
		$this->assertStringContainsString('bg-danger', $this->helper->statusBadge('rejected'));
		$this->assertStringContainsString('bg-secondary', $this->helper->statusBadge('unknown'));
	}

	public function testRecordTypeBadge(): void {
		$newRecord = new BouncerRecord([
			'primary_key' => null,
		]);
		$result = $this->helper->recordTypeBadge($newRecord);
		$this->assertStringContainsString('New Record', $result);
		$this->assertStringContainsString('bg-success', $result);

		$editRecord = new BouncerRecord([
			'primary_key' => 42,
		]);
		$result = $this->helper->recordTypeBadge($editRecord);
		$this->assertStringContainsString('Edit to Record', $result);
		$this->assertStringContainsString('42', $result);
		$this->assertStringContainsString('bg-info', $result);
	}

	public function testDiffStyles(): void {
		$result = $this->helper->diffStyles();

		$this->assertStringContainsString('<style>', $result);
		$this->assertStringContainsString('.diff-wrapper', $result);
		$this->assertStringContainsString('</style>', $result);
	}

	public function testDiffToggleButtons(): void {
		$result = $this->helper->diffToggleButtons();

		$this->assertStringContainsString('btn-group', $result);
		$this->assertStringContainsString('btn-inline-diff', $result);
		$this->assertStringContainsString('btn-side-diff', $result);
		$this->assertStringContainsString('Inline', $result);
		$this->assertStringContainsString('Side-by-side', $result);
	}

	public function testDiffToggleScript(): void {
		$result = $this->helper->diffToggleScript();

		$this->assertStringContainsString('<script>', $result);
		$this->assertStringContainsString('addEventListener', $result);
		$this->assertStringContainsString('</script>', $result);
	}

	public function testFormatValueWithNull(): void {
		$result = $this->helper->formatValue(null);

		$this->assertStringContainsString('null', $result);
		$this->assertStringContainsString('text-muted', $result);
	}

	public function testFormatValueWithBool(): void {
		$trueResult = $this->helper->formatValue(true);
		$falseResult = $this->helper->formatValue(false);

		$this->assertStringContainsString('true', $trueResult);
		$this->assertStringContainsString('bg-success', $trueResult);
		$this->assertStringContainsString('false', $falseResult);
		$this->assertStringContainsString('bg-secondary', $falseResult);
	}

	public function testFormatValueWithArray(): void {
		$result = $this->helper->formatValue(['key' => 'value']);

		$this->assertStringContainsString('<pre', $result);
		$this->assertStringContainsString('<code>', $result);
		$this->assertStringContainsString('key', $result);
	}

	public function testFormatValueEscapesHtml(): void {
		$result = $this->helper->formatValue('<script>alert("xss")</script>');

		$this->assertStringContainsString('&lt;script&gt;', $result);
		$this->assertStringNotContainsString('<script>alert', $result);
	}

}
