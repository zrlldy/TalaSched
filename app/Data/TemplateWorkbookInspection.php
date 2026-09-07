<?php

namespace App\Data;

final readonly class TemplateWorkbookInspection
{
    /**
     * @param  list<array{
     *     dimensions: array{columns: int, range: string, rows: int},
     *     merged_cell_count: int,
     *     merged_cells: list<string>,
     *     merged_cells_truncated: bool,
     *     name: string,
     *     preview: array{
     *         columns: list<string>,
     *         rows: list<array{
     *             cells: list<array{coordinate: string, is_formula: bool, value: bool|float|int|string|null}>,
     *             row: int
     *         }>,
     *         truncated: bool
     *     }
     * }>  $worksheets
     */
    public function __construct(public array $worksheets) {}
}
