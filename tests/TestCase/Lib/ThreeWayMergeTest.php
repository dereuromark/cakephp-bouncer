<?php

declare(strict_types=1);

namespace Bouncer\Test\TestCase\Lib;

use Bouncer\Lib\ThreeWayMerge;
use Cake\TestSuite\TestCase;

/**
 * ThreeWayMerge Test Case
 */
class ThreeWayMergeTest extends TestCase
{
    /**
     * @var \Bouncer\Lib\ThreeWayMerge
     */
    protected ThreeWayMerge $merger;

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->merger = new ThreeWayMerge();
    }

    /**
     * Test when only current changed
     *
     * @return void
     */
    public function testMergeOnlyCurrentChanged(): void
    {
        $result = $this->merger->mergeStrings(
            'original text',
            'modified text',
            'original text',
        );

        $this->assertEquals(ThreeWayMerge::MERGED, $result['status']);
        $this->assertEquals('modified text', $result['result']);
    }

    /**
     * Test when only proposed changed
     *
     * @return void
     */
    public function testMergeOnlyProposedChanged(): void
    {
        $result = $this->merger->mergeStrings(
            'original text',
            'original text',
            'proposed text',
        );

        $this->assertEquals(ThreeWayMerge::MERGED, $result['status']);
        $this->assertEquals('proposed text', $result['result']);
    }

    /**
     * Test when both changed to same value
     *
     * @return void
     */
    public function testMergeBothChangedSame(): void
    {
        $result = $this->merger->mergeStrings(
            'original text',
            'same change',
            'same change',
        );

        $this->assertEquals(ThreeWayMerge::MERGED, $result['status']);
        $this->assertEquals('same change', $result['result']);
    }

    /**
     * Test non-overlapping changes - current changes start, proposed changes end
     *
     * @return void
     */
    public function testMergeNonOverlappingChangesStartEnd(): void
    {
        $result = $this->merger->mergeStrings(
            '#### sdfsdfdfsdfsx!!!!1235411a',
            '#### 111dfsx!!!!1235411a', // changed middle
            '#### sdfsdfdfsdfsx!!!!1235411a!!!!!', // added at end
        );

        $this->assertEquals(ThreeWayMerge::MERGED, $result['status']);
        $this->assertEquals('#### 111dfsx!!!!1235411a!!!!!', $result['result']);
    }

    /**
     * Test non-overlapping changes - proposed changes start, current changes end
     *
     * @return void
     */
    public function testMergeNonOverlappingChangesEndStart(): void
    {
        $result = $this->merger->mergeStrings(
            'Hello World!',
            'Hello World! Goodbye.', // added at end
            'Hi World!', // changed start
        );

        $this->assertEquals(ThreeWayMerge::MERGED, $result['status']);
        $this->assertEquals('Hi World! Goodbye.', $result['result']);
    }

    /**
     * Test overlapping changes - true conflict
     *
     * @return void
     */
    public function testMergeOverlappingChangesConflict(): void
    {
        $result = $this->merger->mergeStrings(
            'Hello World',
            'Hello Universe', // changed "World" to "Universe"
            'Hello Planet', // changed "World" to "Planet"
        );

        $this->assertEquals(ThreeWayMerge::CONFLICT, $result['status']);
    }

    /**
     * Test prefix addition on one side, suffix addition on other
     *
     * @return void
     */
    public function testMergePrefixAndSuffixAdditions(): void
    {
        $result = $this->merger->mergeStrings(
            'middle',
            'prefix middle', // added prefix
            'middle suffix', // added suffix
        );

        $this->assertEquals(ThreeWayMerge::MERGED, $result['status']);
        $this->assertEquals('prefix middle suffix', $result['result']);
    }

    /**
     * Test deletion on one side, addition on other side (non-overlapping)
     *
     * @return void
     */
    public function testMergeDeletionAndAddition(): void
    {
        $result = $this->merger->mergeStrings(
            'abc def ghi',
            'abc ghi', // deleted "def "
            'abc def ghi jkl', // added " jkl"
        );

        $this->assertEquals(ThreeWayMerge::MERGED, $result['status']);
        $this->assertEquals('abc ghi jkl', $result['result']);
    }

    /**
     * Test with empty original
     *
     * @return void
     */
    public function testMergeEmptyOriginal(): void
    {
        $result = $this->merger->mergeStrings(
            '',
            'current added',
            'proposed added',
        );

        // Both added different content - conflict
        $this->assertEquals(ThreeWayMerge::CONFLICT, $result['status']);
    }

    /**
     * Test with one side becoming empty
     *
     * @return void
     */
    public function testMergeOneBecomesEmpty(): void
    {
        $result = $this->merger->mergeStrings(
            'some text',
            '',
            'some text',
        );

        $this->assertEquals(ThreeWayMerge::MERGED, $result['status']);
        $this->assertEquals('', $result['result']);
    }

    /**
     * Test multiline text merge
     *
     * @return void
     */
    public function testMergeMultilineText(): void
    {
        $original = "Line 1\nLine 2\nLine 3";
        $current = "Line 1 modified\nLine 2\nLine 3"; // Changed first line
        $proposed = "Line 1\nLine 2\nLine 3 modified"; // Changed last line

        $result = $this->merger->mergeStrings($original, $current, $proposed);

        $this->assertEquals(ThreeWayMerge::MERGED, $result['status']);
        $this->assertEquals("Line 1 modified\nLine 2\nLine 3 modified", $result['result']);
    }

    /**
     * Test UTF-8 characters
     *
     * @return void
     */
    public function testMergeUtf8Characters(): void
    {
        $result = $this->merger->mergeStrings(
            'Hello 世界',
            'Hi 世界', // Changed start
            'Hello 世界!', // Added at end
        );

        $this->assertEquals(ThreeWayMerge::MERGED, $result['status']);
        $this->assertEquals('Hi 世界!', $result['result']);
    }

    /**
     * Test identical strings (no change)
     *
     * @return void
     */
    public function testMergeIdenticalStrings(): void
    {
        $result = $this->merger->mergeStrings(
            'unchanged',
            'unchanged',
            'unchanged',
        );

        $this->assertEquals(ThreeWayMerge::MERGED, $result['status']);
        $this->assertEquals('unchanged', $result['result']);
    }

    /**
     * Test describe changes shows additions
     *
     * @return void
     */
    public function testDescribeChangesAddition(): void
    {
        $result = $this->merger->mergeStrings(
            'text',
            'text',
            'text added',
        );

        $this->assertNotEmpty($result['proposedChanges']);
        $this->assertStringContainsString('Added', $result['proposedChanges'][0]);
    }

    /**
     * Test describe changes shows removals
     *
     * @return void
     */
    public function testDescribeChangesRemoval(): void
    {
        $result = $this->merger->mergeStrings(
            'text to remove',
            'text',
            'text to remove',
        );

        $this->assertNotEmpty($result['currentChanges']);
        $this->assertStringContainsString('Removed', $result['currentChanges'][0]);
    }
}
