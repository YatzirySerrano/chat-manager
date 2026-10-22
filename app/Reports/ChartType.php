<?php

namespace App\Reports;

enum ChartType: string
{
    case Bar = 'bar';
    case Donut = 'donut';
    case Line = 'line';
}
