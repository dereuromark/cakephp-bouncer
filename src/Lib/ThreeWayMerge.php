<?php

declare(strict_types=1);

namespace Bouncer\Lib;

/**
 * Three-way merge implementation for conflict resolution.
 *
 * Attempts to automatically merge changes from two divergent versions
 * based on a common ancestor (original).
 */
class ThreeWayMerge
{
    /**
     * Merge result indicating successful auto-merge.
     *
     * @var string
     */
    public const MERGED = 'merged';

    /**
     * Merge result indicating a conflict that requires manual resolution.
     *
     * @var string
     */
    public const CONFLICT = 'conflict';

    /**
     * Perform a 3-way merge on string values.
     *
     * @param string $original The common ancestor
     * @param string $current The current live version
     * @param string $proposed The proposed changes
     *
     * @return array{status: string, result: string, currentChanges: array, proposedChanges: array}
     */
    public function mergeStrings(string $original, string $current, string $proposed): array
    {
        // If current equals original, just take proposed
        if ($current === $original) {
            return [
                'status' => self::MERGED,
                'result' => $proposed,
                'currentChanges' => [],
                'proposedChanges' => $this->describeChanges($original, $proposed),
            ];
        }

        // If proposed equals original, just take current
        if ($proposed === $original) {
            return [
                'status' => self::MERGED,
                'result' => $current,
                'currentChanges' => $this->describeChanges($original, $current),
                'proposedChanges' => [],
            ];
        }

        // If current and proposed are the same, no conflict
        if ($current === $proposed) {
            return [
                'status' => self::MERGED,
                'result' => $current,
                'currentChanges' => $this->describeChanges($original, $current),
                'proposedChanges' => $this->describeChanges($original, $proposed),
            ];
        }

        // Try character-level 3-way merge
        return $this->mergeCharacters($original, $current, $proposed);
    }

    /**
     * Perform character-level 3-way merge using fallback approach.
     *
     * @param string $original
     * @param string $current
     * @param string $proposed
     *
     * @return array{status: string, result: string, currentChanges: array, proposedChanges: array}
     */
    protected function mergeCharacters(string $original, string $current, string $proposed): array
    {
        // Use the simpler fallback merge which handles non-overlapping changes
        return $this->fallbackMerge($original, $current, $proposed);
    }

    /**
     * Fallback merge using a simpler diff-patch approach.
     *
     * @param string $original
     * @param string $current
     * @param string $proposed
     *
     * @return array{status: string, result: string, currentChanges: array, proposedChanges: array}
     */
    protected function fallbackMerge(string $original, string $current, string $proposed): array
    {
        // Find where current diverges from original (from start)
        $currPrefixLen = $this->commonPrefixLength($original, $current);
        // Find where current diverges from original (from end)
        $currSuffixLen = $this->commonSuffixLength($original, $current);

        // Same for proposed
        $propPrefixLen = $this->commonPrefixLength($original, $proposed);
        $propSuffixLen = $this->commonSuffixLength($original, $proposed);

        $origLen = mb_strlen($original);
        $currLen = mb_strlen($current);
        $propLen = mb_strlen($proposed);

        // Avoid overlap - check against both strings
        $currMinLen = min($origLen, $currLen);
        if ($currPrefixLen + $currSuffixLen > $currMinLen) {
            $currSuffixLen = $currMinLen - $currPrefixLen;
        }
        $propMinLen = min($origLen, $propLen);
        if ($propPrefixLen + $propSuffixLen > $propMinLen) {
            $propSuffixLen = $propMinLen - $propPrefixLen;
        }

        // Current change region in original: [currPrefixLen, origLen - currSuffixLen)
        // Proposed change region in original: [propPrefixLen, origLen - propSuffixLen)
        $currChangeStart = $currPrefixLen;
        $currChangeEnd = $origLen - $currSuffixLen;
        $propChangeStart = $propPrefixLen;
        $propChangeEnd = $origLen - $propSuffixLen;

        // Special case: if both sides insert at the same position (same change region),
        // they conflict unless they insert the same content
        if ($currChangeStart === $propChangeStart && $currChangeEnd === $propChangeEnd) {
            // Both changed the same region - this is a conflict
            return [
                'status' => self::CONFLICT,
                'result' => $proposed,
                'currentChanges' => $this->describeChanges($original, $current),
                'proposedChanges' => $this->describeChanges($original, $proposed),
            ];
        }

        // Check if regions overlap
        if ($currChangeEnd <= $propChangeStart) {
            // Current changes before proposed - apply current first, then proposed
            $result = $this->applySequentialChanges($original, $current, $proposed, $currChangeStart, $currChangeEnd, $propChangeStart, $propChangeEnd);

            return [
                'status' => self::MERGED,
                'result' => $result,
                'currentChanges' => $this->describeChanges($original, $current),
                'proposedChanges' => $this->describeChanges($original, $proposed),
            ];
        }

        if ($propChangeEnd <= $currChangeStart) {
            // Proposed changes before current - apply proposed first, then current
            $result = $this->applySequentialChanges($original, $proposed, $current, $propChangeStart, $propChangeEnd, $currChangeStart, $currChangeEnd);

            return [
                'status' => self::MERGED,
                'result' => $result,
                'currentChanges' => $this->describeChanges($original, $current),
                'proposedChanges' => $this->describeChanges($original, $proposed),
            ];
        }

        // Regions overlap - true conflict
        return [
            'status' => self::CONFLICT,
            'result' => $proposed,
            'currentChanges' => $this->describeChanges($original, $current),
            'proposedChanges' => $this->describeChanges($original, $proposed),
        ];
    }

    /**
     * Apply two non-overlapping changes sequentially.
     *
     * @param string $original
     * @param string $first Version with earlier change
     * @param string $second Version with later change
     * @param int $firstStart Start of first change in original
     * @param int $firstEnd End of first change in original
     * @param int $secondStart Start of second change in original
     * @param int $secondEnd End of second change in original
     *
     * @return string
     */
    protected function applySequentialChanges(
        string $original,
        string $first,
        string $second,
        int $firstStart,
        int $firstEnd,
        int $secondStart,
        int $secondEnd,
    ): string {
        $origLen = mb_strlen($original);
        $firstLen = mb_strlen($first);
        $secondLen = mb_strlen($second);

        // Calculate the suffix length in original for each version
        $firstSuffixLen = $origLen - $firstEnd;
        $secondSuffixLen = $origLen - $secondEnd;

        // Extract the replacement from first version
        // It's what's between the prefix and suffix in the first string
        $firstReplacementLen = $firstLen - $firstStart - $firstSuffixLen;
        $firstReplacement = $firstReplacementLen > 0 ? mb_substr($first, $firstStart, $firstReplacementLen) : '';

        // Extract the replacement from second version
        $secondReplacementLen = $secondLen - $secondStart - $secondSuffixLen;
        $secondReplacement = $secondReplacementLen > 0 ? mb_substr($second, $secondStart, $secondReplacementLen) : '';

        // Build result: prefix + first replacement + middle + second replacement + suffix
        $result = mb_substr($original, 0, $firstStart);
        $result .= $firstReplacement;
        $result .= mb_substr($original, $firstEnd, $secondStart - $firstEnd);
        $result .= $secondReplacement;

        return $result . mb_substr($original, $secondEnd);
    }

    /**
     * Get length of common prefix between two strings.
     *
     * @param string $a
     * @param string $b
     *
     * @return int
     */
    protected function commonPrefixLength(string $a, string $b): int
    {
        $aChars = $this->toChars($a);
        $bChars = $this->toChars($b);
        $len = min(count($aChars), count($bChars));

        for ($i = 0; $i < $len; $i++) {
            if ($aChars[$i] !== $bChars[$i]) {
                return $i;
            }
        }

        return $len;
    }

    /**
     * Get length of common suffix between two strings.
     *
     * @param string $a
     * @param string $b
     *
     * @return int
     */
    protected function commonSuffixLength(string $a, string $b): int
    {
        $aChars = $this->toChars($a);
        $bChars = $this->toChars($b);
        $aLen = count($aChars);
        $bLen = count($bChars);
        $len = min($aLen, $bLen);

        for ($i = 0; $i < $len; $i++) {
            if ($aChars[$aLen - 1 - $i] !== $bChars[$bLen - 1 - $i]) {
                return $i;
            }
        }

        return $len;
    }

    /**
     * Convert string to array of characters (UTF-8 safe).
     *
     * @param string $str
     *
     * @return array<string>
     */
    protected function toChars(string $str): array
    {
        return preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Find common prefix of multiple strings.
     *
     * @param array<string> $strings
     *
     * @return string
     */
    protected function commonPrefix(array $strings): string
    {
        if (!$strings) {
            return '';
        }

        $first = $this->toChars($strings[0]);
        $prefix = [];
        $firstLen = count($first);

        for ($i = 0; $i < $firstLen; $i++) {
            $char = $first[$i];
            foreach ($strings as $str) {
                $chars = $this->toChars($str);
                if (!isset($chars[$i]) || $chars[$i] !== $char) {
                    return implode('', $prefix);
                }
            }
            $prefix[] = $char;
        }

        return implode('', $prefix);
    }

    /**
     * Find common suffix of multiple strings.
     *
     * @param array<string> $strings
     *
     * @return string
     */
    protected function commonSuffix(array $strings): string
    {
        if (!$strings) {
            return '';
        }

        $reversed = array_map($this->reverseString(...), $strings);
        $prefix = $this->commonPrefix($reversed);

        return $this->reverseString($prefix);
    }

    /**
     * Reverse a UTF-8 string.
     *
     * @param string $str
     *
     * @return string
     */
    protected function reverseString(string $str): string
    {
        $chars = $this->toChars($str);

        return implode('', array_reverse($chars));
    }

    /**
     * Describe the changes between two strings.
     *
     * @param string $from
     * @param string $to
     *
     * @return array<string>
     */
    protected function describeChanges(string $from, string $to): array
    {
        if ($from === $to) {
            return [];
        }

        $changes = [];

        // Find common prefix/suffix to identify what changed
        $prefix = $this->commonPrefix([$from, $to]);
        $suffix = $this->commonSuffix([$from, $to]);

        $prefixLen = mb_strlen($prefix);
        $suffixLen = mb_strlen($suffix);
        $fromLen = mb_strlen($from);
        $toLen = mb_strlen($to);

        // Avoid overlap
        if ($prefixLen + $suffixLen > min($fromLen, $toLen)) {
            $suffixLen = max(0, min($fromLen, $toLen) - $prefixLen);
        }

        $fromMiddle = mb_substr($from, $prefixLen, $fromLen - $prefixLen - $suffixLen);
        $toMiddle = mb_substr($to, $prefixLen, $toLen - $prefixLen - $suffixLen);

        if ($fromMiddle === '' && $toMiddle !== '') {
            $changes[] = sprintf('Added "%s"', $this->truncate($toMiddle, 30));
        } elseif ($fromMiddle !== '' && $toMiddle === '') {
            $changes[] = sprintf('Removed "%s"', $this->truncate($fromMiddle, 30));
        } elseif ($fromMiddle !== $toMiddle) {
            $changes[] = sprintf('Changed "%s" → "%s"', $this->truncate($fromMiddle, 20), $this->truncate($toMiddle, 20));
        }

        return $changes;
    }

    /**
     * Truncate a string with ellipsis.
     *
     * @param string $str
     * @param int $maxLen
     *
     * @return string
     */
    protected function truncate(string $str, int $maxLen): string
    {
        if (mb_strlen($str) <= $maxLen) {
            return $str;
        }

        return mb_substr($str, 0, $maxLen - 3) . '...';
    }

    /**
     * Perform a 3-way merge on arrays of data (entity fields).
     *
     * This merges field-by-field, using string merging for text fields
     * when both sides have changed.
     *
     * @param array<string, mixed> $original The original data when draft was created
     * @param array<string, mixed> $current The current live data
     * @param array<string, mixed> $proposed The proposed changes
     * @param array<string> $skipFields Fields to skip during merge (e.g., 'id', 'created', 'modified')
     *
     * @return array{merged: array<string, mixed>, conflicts: array<string, array>, autoMerged: array<string, array>, hasConflicts: bool}
     */
    public function mergeArrays(
        array $original,
        array $current,
        array $proposed,
        array $skipFields = ['id', 'created', 'modified', '_delete'],
    ): array {
        $conflicts = [];
        $autoMerged = [];
        $merged = $proposed;

        $allFields = array_unique(array_merge(
            array_keys($original),
            array_keys($current),
            array_keys($proposed),
        ));

        // Filter out skip fields
        $allFields = array_filter($allFields, function ($field) use ($skipFields) {
            return !in_array($field, $skipFields, true);
        });

        foreach ($allFields as $field) {
            $origValue = $original[$field] ?? null;
            $currValue = $current[$field] ?? null;
            $propValue = $proposed[$field] ?? null;

            // Skip fields not in the original proposal
            if (!array_key_exists($field, $proposed)) {
                continue;
            }

            // Skip if no changes
            $currentChanged = $origValue !== $currValue;
            $proposedChanged = $origValue !== $propValue;

            if (!$currentChanged && !$proposedChanged) {
                continue;
            }

            // If only one side changed, use that change
            if (!$currentChanged) {
                // Only proposed changed - keep proposed value (already in $merged)
                continue;
            }
            if (!$proposedChanged) {
                // Only current changed - use current value
                $merged[$field] = $currValue;
                $autoMerged[$field] = [
                    'original' => $origValue,
                    'current' => $currValue,
                    'proposed' => $propValue,
                    'merged' => $currValue,
                    'reason' => 'current_only',
                ];

                continue;
            }

            // Both sides changed - attempt text merge for strings
            if (is_string($origValue) && is_string($currValue) && is_string($propValue)) {
                $mergeResult = $this->mergeStrings($origValue, $currValue, $propValue);

                if ($mergeResult['status'] === self::MERGED) {
                    $merged[$field] = $mergeResult['result'];
                    $autoMerged[$field] = [
                        'original' => $origValue,
                        'current' => $currValue,
                        'proposed' => $propValue,
                        'merged' => $mergeResult['result'],
                        'reason' => 'text_merge',
                    ];

                    continue;
                }
            }

            // Real conflict - both changed to different values
            $conflicts[$field] = [
                'original' => $origValue,
                'current' => $currValue,
                'proposed' => $propValue,
            ];
            // Keep proposed value as default for conflicts
            $merged[$field] = $propValue;
        }

        return [
            'merged' => $merged,
            'conflicts' => $conflicts,
            'autoMerged' => $autoMerged,
            'hasConflicts' => (bool)$conflicts,
        ];
    }
}
