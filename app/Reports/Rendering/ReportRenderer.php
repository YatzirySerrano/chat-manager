<?php

namespace App\Reports\Rendering;

use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns a report definition into a file of one format.
 */
interface ReportRenderer
{
    /**
     * Write the report to the given path.
     *
     * @param  ReportDefinition<Model>  $definition
     * @param  int  $maxRows  Hard limit enforced while streaming rows.
     * @return int Rows written to the main table.
     */
    public function render(ReportDefinition $definition, ReportContext $context, string $path, int $maxRows): int;
}
