<?php

namespace App\Data\Templates;

final readonly class TemplateCellRange
{
    public function __construct(
        public string $startCell,
        public string $endCell,
    ) {}
}
