<?php

namespace App\Reports;

/**
 * Aggregate shown in the totals row under a column.
 */
enum ColumnTotal: string
{
    case None = 'none';
    case Sum = 'sum';
    case Count = 'count';
}
