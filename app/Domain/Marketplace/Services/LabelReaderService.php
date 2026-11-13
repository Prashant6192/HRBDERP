<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Contracts\AiLabelReader;
use App\Domain\Marketplace\Contracts\LabelFileParser;
use App\Domain\Marketplace\Contracts\LabelSheetParser;
use App\Domain\Marketplace\Contracts\LabelTextParser;
use App\Domain\Marketplace\DTOs\LabelExtraction;
use App\Domain\Marketplace\DTOs\LabelReading;
use App\Domain\Marketplace\Enums\LabelReaderKind;
use App\Domain\Marketplace\Exceptions\OnlineOrderException;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\Marketplace\Readers\AmazonLabelParser;
use App\Domain\Marketplace\Readers\FlipkartLabelParser;
use App\Domain\Marketplace\Readers\MeeshoLabelParser;
use App\Domain\Marketplace\Readers\MyntraLabelParser;
use App\Domain\Marketplace\Readers\ShiprocketLabelParser;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Turns a label PDF into parcels.
 *
 * Where the marketplace prints text (Meesho, Flipkart) each page is read
 * exactly, on this server, at no cost. Myntra's pages are pictures: the
 * uploader's browser reads their words and barcodes and sends them with
 * the file ($seen), and the Myntra parser reads those — no AI involved.
 * Amazon's labels are pictures read the same way, and each is joined with
 * the tax invoice after it, which is text read here. Pages a reader could
 * not place go to the AI reader when there is one. A page nobody could
 * read still becomes a parcel, flagged, so it is never silently dropped
 * from the day's work.
 */
class LabelReaderService
{
    /** Longer files are not sent to the AI reader in one request. */
    public const int AI_MAX_PAGES = 12;

    public function __construct(private readonly AiLabelReader $ai) {}

    /**
     * @param  array<int, string>  $seen  what the browser read on picture pages, by page number
     */
    public function read(string $contents, string $filename, Marketplace $marketplace, array $seen = []): LabelReading
    {
        [$texts, $pageCount] = $this->pages($contents);

        if ($pageCount === 0) {
            throw new OnlineOrderException("{$filename} has no pages that could be opened. Check it is the label PDF the marketplace gave you.");
        }

        $this->assertRightMarketplace($texts, $filename, $marketplace);

        $parser = $this->parserFor($marketplace->reader);
        $parcels = [];
        $covered = [];

        if ($parser instanceof LabelFileParser) {
            // Amazon: a parcel is its label page and the invoice after it.
            $pages = [];

            foreach ($texts as $page => $text) {
                $pages[$page] = trim($text) === '' && isset($seen[$page]) ? $seen[$page] : $text;
            }

            foreach ($parser->parseFile($pages) as $parcel) {
                if ($parcel->pages === [] || $parcel->isBlank()) {
                    continue;
                }

                // Two labels can share a page (Shiprocket prints two to one).
                $parcels[$parcel->pages[0]][] = $parcel;
                $covered = array_values(array_unique([...$covered, ...$parcel->pages]));
            }
        } elseif ($parser !== null) {
            foreach ($texts as $page => $text) {
                if (trim($text) === '' && isset($seen[$page])) {
                    $text = $seen[$page];
                }

                if (trim($text) === '') {
                    continue;
                }

                // Shiprocket prints two labels to a page.
                $found = $parser instanceof LabelSheetParser
                    ? array_values(array_filter($parser->parsePage($text, $page), fn (LabelExtraction $p) => ! $p->isBlank()))
                    : array_filter([$parser->parse($text, $page)], fn (?LabelExtraction $p) => $p !== null && ! $p->isBlank());

                if ($found !== []) {
                    $parcels[$page] = array_values($found);
                    $covered[] = $page;
                }
            }
        }

        $unread = array_values(array_diff(range(1, $pageCount), $covered));

        if ($unread === []) {
            ksort($parcels);

            return new LabelReading(array_merge(...array_values($parcels)), $marketplace->reader->value, pageCount: $pageCount);
        }

        $warnings = [];
        $ignored = [];
        $model = null;
        $readWith = $parcels === [] ? 'ai' : $marketplace->reader->value.'+ai';

        if ($marketplace->reader === LabelReaderKind::Myntra) {
            // Myntra is never sent to the AI reader.
            $readWith = $marketplace->reader->value;
            $warnings[] = $seen === []
                ? 'These pages are pictures and this upload did not bring their reading. Upload from the ERP\'s Upload labels page in Chrome or Edge so the computer reads them, or type the AWB and product on each flagged parcel.'
                : count($unread).' page(s) could not be read. Type their AWB and product on each flagged parcel.';
        } elseif ($this->ai->available() && $pageCount > self::AI_MAX_PAGES) {
            // The AI reader takes the whole file in one request: a long file
            // runs past the web server's time limit (a 504 at the gateway).
            $readWith = $parcels === [] ? 'none' : $marketplace->reader->value;
            $warnings[] = count($unread).' page(s) could not be read here, and the file is too long ('.$pageCount.' pages) to send to the AI reader. '
                .($marketplace->reader->readsInBrowser() ? 'Upload from the ERP\'s Upload labels page in Chrome or Edge so the computer reads the label pictures, or type' : 'Type')
                .' the AWB and product on each flagged parcel.';
        } elseif ($this->ai->available()) {
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

                $parcels[$pages[0]] = [$parcel];
                $unread = array_values(array_diff($unread, $pages));
            }

            $ignored = array_values(array_intersect($reading->ignoredPages, $unread));
            $unread = array_values(array_diff($unread, $ignored));
        } elseif ($marketplace->reader->readsInBrowser() && $seen === []) {
            $readWith = $parcels === [] ? 'none' : $marketplace->reader->value;
            $warnings[] = 'These pages are pictures and this upload did not bring their reading. Upload from the ERP\'s Upload labels page in Chrome or Edge so the computer reads them, or type the AWB and product on each flagged parcel.';
        } else {
            $readWith = $parcels === [] ? 'none' : $marketplace->reader->value;
            $warnings[] = 'The AI label reader is not set up on this server (ANTHROPIC_API_KEY), so pages without text could not be read. Type their AWB and product on each flagged parcel.';
        }

        // Nobody could read these pages. They still become parcels, flagged
        // for someone to fill in, rather than vanishing.
        foreach ($unread as $page) {
            $parcels[$page] = [new LabelExtraction(pages: [$page], warnings: ['This page could not be read. Type the AWB, courier and product.'])];
        }

        ksort($parcels);

        return new LabelReading(array_merge(...array_values($parcels)), $readWith, $model, $ignored, $warnings, $pageCount);
    }

    /**
     * A file chosen under the wrong marketplace reads as nothing: a Meesho
     * PDF under Myntra became 146 parcels with no AWB, product or courier.
     * Meesho's and Flipkart's labels print text that says whose they are,
     * so a file most of whose pages read as another marketplace's labels
     * is refused, naming the right one.
     *
     * @param  array<int, string>  $texts
     */
    private function assertRightMarketplace(array $texts, string $filename, Marketplace $marketplace): void
    {
        $pages = array_filter($texts, fn (string $t) => trim($t) !== '');

        // Meesho's and Flipkart's labels always carry text; a file of
        // pictures only is a Myntra or Amazon file chosen under them.
        if ($pages === []) {
            // (The website's labels can be all pictures: Amazon Shipping's.)
            if (in_array($marketplace->reader, [LabelReaderKind::Meesho, LabelReaderKind::Flipkart], true)) {
                throw new OnlineOrderException("{$filename} has only pictures, unlike {$marketplace->name}'s labels. If it is a Myntra or Amazon file, upload it again with that marketplace selected.");
            }

            return;
        }

        // Amazon's invoices are text; under another marketplace the file
        // would read as labels with no AWB.
        if ($marketplace->reader !== LabelReaderKind::Amazon && $marketplace->reader !== LabelReaderKind::Ai
            && count(array_filter($pages, fn (string $t) => AmazonLabelParser::isInvoice($t))) * 2 >= count($pages)) {
            $name = Marketplace::query()->where('reader', LabelReaderKind::Amazon->value)->value('name') ?? 'Amazon';

            throw new OnlineOrderException("{$filename} looks like {$name} labels, but {$marketplace->name} was chosen. Upload it again with {$name} selected.");
        }

        foreach ([LabelReaderKind::Meesho, LabelReaderKind::Flipkart] as $kind) {
            if ($kind === $marketplace->reader) {
                continue;
            }

            $parser = $this->parserFor($kind);
            $read = 0;

            foreach ($pages as $page => $text) {
                $parcel = $parser instanceof LabelTextParser ? $parser->parse($text, $page) : null;

                if ($parcel !== null && $parcel->awb !== null) {
                    $read++;
                }
            }

            if ($read * 2 >= count($texts)) {
                $name = Marketplace::query()->where('reader', $kind->value)->value('name') ?? ucfirst($kind->value);

                throw new OnlineOrderException("{$filename} looks like {$name} labels, but {$marketplace->name} was chosen. Upload it again with {$name} selected.");
            }
        }
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

    private function parserFor(LabelReaderKind $kind): LabelTextParser|LabelFileParser|null
    {
        return match ($kind) {
            LabelReaderKind::Meesho => new MeeshoLabelParser,
            LabelReaderKind::Flipkart => new FlipkartLabelParser,
            LabelReaderKind::Myntra => new MyntraLabelParser,
            LabelReaderKind::Amazon => new AmazonLabelParser,
            LabelReaderKind::Shiprocket => new ShiprocketLabelParser,
            LabelReaderKind::Ai => null,
        };
    }
}
