<?php

declare(strict_types=1);

namespace App\Support\Pdf;

use Smalot\PdfParser\Parser;
use Throwable;

/**
 * The words on a PDF, as rows.
 *
 * A bill printed from accounting software carries its text as text, with a
 * position for every run. Runs at the same height are one row, left to
 * right, which is how a person reads a table. Some PDFs carry unusable
 * positions; for those the parser's plain reading order is used instead.
 * A scanned bill or a photo carries no text at all.
 */
final class PdfText
{
    /**
     * @param  list<array{y: float, cells: list<array{x: float, t: string}>, text: string}>  $rows
     */
    private function __construct(
        public readonly array $rows,
        public readonly string $text,
        public readonly bool $positional,
    ) {}

    public static function read(string $contents): self
    {
        $parser = new Parser;

        try {
            $document = $parser->parseContent($contents);
        } catch (Throwable) {
            return new self([], '', false);
        }

        $rows = [];
        $degenerate = 0;
        $cells = 0;

        foreach ($document->getPages() as $page) {
            try {
                $runs = $page->getDataTm();
            } catch (Throwable) {
                $runs = [];
            }

            $items = [];

            foreach ($runs as $run) {
                [$tm, $text] = $run;
                $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');

                if ($text === '') {
                    continue;
                }

                $items[] = ['x' => (float) ($tm[4] ?? 0), 'y' => (float) ($tm[5] ?? 0), 't' => $text];
            }

            usort($items, fn (array $a, array $b) => ($b['y'] <=> $a['y']) ?: ($a['x'] <=> $b['x']));

            $current = null;

            foreach ($items as $item) {
                if ($current === null || abs($current['y'] - $item['y']) > 3.0) {
                    if ($current !== null) {
                        $rows[] = self::finish($current);
                    }

                    $current = ['y' => $item['y'], 'cells' => []];
                }

                $current['cells'][] = $item;
            }

            if ($current !== null) {
                $rows[] = self::finish($current);
            }
        }

        foreach ($rows as $row) {
            $cells += count($row['cells']);
            $xs = array_map(fn (array $c) => round($c['x']), $row['cells']);

            // Many runs stacked at one x on one y: the positions are not real.
            if (count($xs) >= 6 && count(array_unique($xs)) <= max(2, (int) (count($xs) / 4))) {
                $degenerate += count($row['cells']);
            }
        }

        $positional = $cells > 0 && $degenerate < $cells / 3;

        try {
            $plain = (string) $document->getText();
        } catch (Throwable) {
            $plain = '';
        }

        if (! $positional) {
            $rows = [];

            foreach (preg_split('/\R/u', $plain) ?: [] as $i => $line) {
                $line = trim(preg_replace('/[ \t]+/u', ' ', $line) ?? '');

                if ($line !== '') {
                    $rows[] = ['y' => (float) -$i, 'cells' => [['x' => 0.0, 't' => $line]], 'text' => $line];
                }
            }
        }

        $text = $positional ? implode("\n", array_map(fn (array $r) => $r['text'], $rows)) : $plain;

        return new self($rows, $text, $positional);
    }

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }

    /**
     * @param  array{y: float, cells: list<array{x: float, y: float, t: string}>}  $row
     * @return array{y: float, cells: list<array{x: float, t: string}>, text: string}
     */
    private static function finish(array $row): array
    {
        usort($row['cells'], fn (array $a, array $b) => $a['x'] <=> $b['x']);
        $cells = array_map(fn (array $c) => ['x' => $c['x'], 't' => $c['t']], $row['cells']);

        return ['y' => $row['y'], 'cells' => $cells, 'text' => implode('  ', array_column($cells, 't'))];
    }
}
