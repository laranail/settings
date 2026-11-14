<?php

declare(strict_types=1);

/*
 * GitHub and docsmith render ```mermaid blocks, and a block that does not parse renders as an
 * error box rather than failing anything. This is a cheap structural check, not a parser: each
 * block names a known diagram type, closes its quotes, balances its brackets outside quoted
 * labels, and names no sequence participant with a word Mermaid reserves (`Actor` broke one).
 */

/** @return array<string, list<string>> file => its mermaid blocks */
function mermaidBlocks(): array
{
    $root = dirname(__DIR__, 2);
    $files = [$root . '/README.md', ...(glob($root . '/docs/*.md') ?: []), ...(glob($root . '/docs/*/*.md') ?: [])];
    $blocks = [];

    foreach ($files as $file) {
        preg_match_all('/^```mermaid\n(.*?)^```$/ms', (string) file_get_contents($file), $matches);
        $blocks[substr($file, strlen($root) + 1)] = $matches[1];
    }

    return $blocks;
}

/** The first structural problem in one block, or null. */
function mermaidProblem(string $block): ?string
{
    $lines = array_values(array_filter(array_map(trim(...), explode("\n", $block)), static fn (string $line): bool => $line !== ''));
    $type = strtok($lines[0] ?? '', ' ');

    if (! in_array($type, ['flowchart', 'graph', 'sequenceDiagram', 'stateDiagram-v2', 'erDiagram', 'classDiagram'], true)) {
        return sprintf('starts with [%s], not a known diagram type', $lines[0] ?? '');
    }

    foreach ($lines as $number => $line) {
        if (substr_count($line, '"') % 2 !== 0) {
            return sprintf('line %d has an unclosed quote: %s', $number + 1, $line);
        }

        if ($type === 'sequenceDiagram' && preg_match('/^participant\s+(actor|participant|end|alt|else|opt|loop|par|note|box)\b/i', $line) === 1) {
            return sprintf('line %d names a participant with a reserved word: %s', $number + 1, $line);
        }
    }

    // Brackets inside a quoted label are text, and an erDiagram cardinality (`|o--o{`) is not a
    // bracket; everything else is syntax and must pair across the block.
    $syntax = (string) preg_replace(['/"[^"]*"/', '/[|}][|o](--|\.\.)[|o][|{]/'], ['""', '--'], implode("\n", $lines));

    foreach (['(' => ')', '[' => ']', '{' => '}'] as $open => $close) {
        if (substr_count($syntax, $open) !== substr_count($syntax, $close)) {
            return sprintf('unbalanced %s%s: %d against %d', $open, $close, substr_count($syntax, $open), substr_count($syntax, $close));
        }
    }

    return null;
}

it('keeps every mermaid diagram in the README and docs well formed', function (): void {
    $blocks = mermaidBlocks();
    $count = array_sum(array_map(count(...), $blocks));

    // 56 files (README and 55 pages) and 9 diagrams, counted 2026-11-14; fewer means a broken glob.
    expect(count($blocks))->toBeGreaterThanOrEqual(56)
        ->and($count)->toBeGreaterThanOrEqual(9);

    foreach ($blocks as $file => $diagrams) {
        foreach ($diagrams as $index => $diagram) {
            expect(mermaidProblem($diagram))->toBeNull(sprintf('%s, diagram %d', $file, $index + 1));
        }
    }
});

it('reports a malformed diagram', function (string $block): void {
    expect(mermaidProblem($block))->not->toBeNull();
})->with([
    'unknown type'       => "flowchart-ish TD\n    a --> b\n",
    'unbalanced bracket' => "flowchart TD\n    a[\"label\" --> b\n",
    'unclosed quote'     => "flowchart TD\n    a[\"label] --> b\n",
    'reserved name'      => "sequenceDiagram\n    participant Actor as ApiActor\n",
]);
