<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Contracts\AiLabelReader;
use App\Domain\Marketplace\Contracts\LabelTextParser;
use App\Domain\Marketplace\DTOs\LabelExtraction;
use App\Domain\Marketplace\DTOs\LabelReading;
use App\Domain\Marketplace\Enums\LabelReaderKind;
use App\Domain\Marketplace\Exceptions\OnlineOrderException;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\Marketplace\Readers\FlipkartLabelParser;
use App\Domain\Marketplace\Readers\MeeshoLabelParser;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Turns a label PDF into parcels.
 *
 * Where the marketplace prints text (Meesho, Flipkart) each page is read
 * exactly, on this server, at no cost. Pages that carry no text — Amazon
 * and Myntra send pictures — or that the text reader could not place go to
 * the AI reader, which also groups an Amazon label with the invoice pages
 * after it. A page nobody could read still becomes a parcel, flagged, so
 * it is never silently dropped from the day's work.
 */
class LabelReaderService
{
    public function __construct(private readonly AiLabelReader $ai) {}

    public function read(string $contents, string $filename, Marketplace $marketplace): LabelReading
    {
        [$texts, $pageCount] = $this->pages($contents);

        if ($pageCount === 0) {
            throw new OnlineOrderException("{$filename} has no pages that could be opened. Check it is the label PDF the marketplace gave you.");
        }

        $parser = $this->parserFor($marketplace->reader);
        $parcels = [];

        if ($parser !== null) {
            foreach ($texts as $page => $text) {
                $parcel = trim($text) === '' ? null : $parser->parse($text, $page);

                if ($parcel !== null && ! $parcel->isBlank()) {
                    $parcels[$page] = $parcel;
                }
            }
        }

        $unread = array_values(array_diff(range(1, $pageCount), array_keys($parcels)));

        if ($unread === []) {
            return new LabelReading(array_values($parcels), $marketplace->reader->value, pageCount: $pageCount);
        }

        $warnings = [];
        $ignored = [];
        $model = null;
        $readWith = $parcels === [] ? 'ai' : $marketplace->reader->value.'+ai';

        if ($this->ai->available()) {
            $reading = $this->ai->read($contents, $filename, $marketplace->name, $pageCount);
            $model = $reading->model;
            $warnings = $reading->warnings;

            foreach ($reading->parcels as $parcel) {
                // Only pages the text reader did not already place, and
                // only pages that exist.
                $pages = array_values(array_intersect($parcel->pages, $unread));

                if ($pages === [] || $pages !== array_values(array_intersect($parcel->pages, range(1, $pageCount)))) {
                    continue;
                }

                $parcels[$pages[0]] = $parcel;
                $unread = array_values(array_diff($unread, $pages));
            }

            $ignored = array_values(array_intersect($reading->ignoredPages, $unread));
            $unread = array_values(array_diff($unread, $ignored));
        } else {
            $readWith = $parcels === [] ? 'none' : $marketplace->reader->value;
            $warnings[] = 'The AI label reader is not set up on this server (ANTHROPIC_API_KEY), so pages without text could not be read. Type their AWB and product on each flagged parcel.';
        }

        // Nobody could read these pages. They still become parcels, flagged
        // for someone to fill in, rather than vanishing.
        foreach ($unread as $page) {
            $parcels[$page] = new LabelExtraction(pages: [$page], warnings: ['This page could not be read. Type the AWB, courier and product.']);
        }

        ksort($parcels);

        return new LabelReading(array_values($parcels), $readWith, $model, $ignored, $warnings, $pageCount);
    }

    /**
     * Each page's text, keyed by page number, and the page count.
     *
     * @return array{0: array<int, string>, 1: int}
     */
    private function pages(string $contents): array
    {
        try {
            $pdf = (new Parser)->parseContent($contents);
            $texts = [];

            foreach ($pdf->getPages() as $i => $page) {
                try {
                    $texts[$i + 1] = $page->getText();
                } catch (Throwable) {
                    $texts[$i + 1] = '';
                }
            }

            return [$texts, count($texts)];
        } catch (Throwable) {
            // The parser could not open it (compressed object streams some
            // generators write): count the pages and leave the reading to
            // the AI reader.
            $count = preg_match_all('/\/Type\s*\/Page(?![s\w])/', $contents);

            return [array_fill(1, max(0, (int) $count), '') ?: [], (int) $count];
        }
    }

    private function parserFor(LabelReaderKind $kind): ?LabelTextParser
    {
        return match ($kind) {
            LabelReaderKind::Meesho => new MeeshoLabelParser,
            LabelReaderKind::Flipkart => new FlipkartLabelParser,
            LabelReaderKind::Ai => null,
        };
    }
}
