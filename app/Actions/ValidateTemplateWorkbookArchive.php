<?php

namespace App\Actions;

use LogicException;
use ZipArchive;

class ValidateTemplateWorkbookArchive
{
    private const int MaximumArchiveEntries = 2_000;

    private const int MaximumCompressionRatio = 100;

    private const int MaximumEntryUncompressedBytes = 8 * 1_024 * 1_024;

    private const int MaximumTotalUncompressedBytes = 25 * 1_024 * 1_024;

    public function validate(string $workbookPath): void
    {
        if (! is_file($workbookPath)) {
            throw new LogicException('The workbook archive could not be opened.');
        }

        $archive = new ZipArchive;

        if ($archive->open($workbookPath, ZipArchive::RDONLY) !== true) {
            throw new LogicException('The workbook must be a readable XLSX archive.');
        }

        try {
            if ($archive->numFiles > self::MaximumArchiveEntries) {
                throw new LogicException('The workbook archive contains too many entries.');
            }

            $hasContentTypes = false;
            $hasWorkbook = false;
            $totalUncompressedBytes = 0;

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);

                if (! is_array($entry)) {
                    throw new LogicException('The workbook archive contains unreadable entry metadata.');
                }

                $name = $entry['name'];
                $uncompressedSize = $entry['size'];
                $compressedSize = $entry['comp_size'];

                if ($uncompressedSize < 0
                    || $compressedSize < 0
                    || ! $this->isSafeEntryName($name)) {
                    throw new LogicException('The workbook archive contains an unsafe entry.');
                }

                if ($uncompressedSize > self::MaximumEntryUncompressedBytes) {
                    throw new LogicException('The workbook archive contains an entry that expands beyond the allowed limit.');
                }

                if ($uncompressedSize > 0
                    && ($compressedSize === 0 || $uncompressedSize > $compressedSize * self::MaximumCompressionRatio)) {
                    throw new LogicException('The workbook archive exceeds the allowed compression ratio.');
                }

                if ($totalUncompressedBytes > self::MaximumTotalUncompressedBytes - $uncompressedSize) {
                    throw new LogicException('The workbook archive expands beyond the allowed total size.');
                }

                $totalUncompressedBytes += $uncompressedSize;
                $normalizedName = str_replace('\\', '/', $name);

                if ($normalizedName === '[Content_Types].xml') {
                    $hasContentTypes = true;
                }

                if ($normalizedName === 'xl/workbook.xml') {
                    $hasWorkbook = true;
                }

                if (strtolower($normalizedName) === 'xl/vbaproject.bin') {
                    throw new LogicException('Macro-enabled workbooks are not supported.');
                }

                if (str_starts_with($normalizedName, 'xl/externalLinks/')) {
                    throw new LogicException('Workbooks with external links are not supported.');
                }
            }

            if (! $hasContentTypes || ! $hasWorkbook) {
                throw new LogicException('The workbook archive is missing required XLSX entries.');
            }

            $workbookRelationships = $archive->getFromName('xl/_rels/workbook.xml.rels');

            if (is_string($workbookRelationships)
                && preg_match('/TargetMode\\s*=\\s*([\'\"])External\\1/i', $workbookRelationships) === 1) {
                throw new LogicException('Workbooks with external links are not supported.');
            }
        } finally {
            $archive->close();
        }
    }

    private function isSafeEntryName(string $name): bool
    {
        $normalizedName = str_replace('\\', '/', $name);

        if ($normalizedName === ''
            || str_starts_with($normalizedName, '/')
            || str_contains($normalizedName, "\0")) {
            return false;
        }

        foreach (explode('/', $normalizedName) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }
}
