{{--
    Single print stylesheet of every report. Fixed light colors (independent
    of the app theme) mirrored from App\Reports\Rendering\ReportPalette and
    system fonts only, so rendering never needs the network.
--}}
<style>
    @page { size: A4 {{ $orientation->value }}; }

    * { box-sizing: border-box; }

    html, body {
        margin: 0;
        padding: 0;
        background: #ffffff;
        color: #121921;
        font-family: -apple-system, BlinkMacSystemFont, 'Helvetica Neue', 'Segoe UI', Roboto, 'Liberation Sans', 'DejaVu Sans', Arial, sans-serif;
        font-size: 9pt;
        line-height: 1.45;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        font-variant-numeric: tabular-nums;
    }

    .muted { color: #616A75; }
    .strong { font-weight: 600; }
    .num { text-align: right; white-space: nowrap; }

    /* Institutional header */
    .masthead {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding-bottom: 10pt;
        border-bottom: 2pt solid #008251;
    }
    .brand { display: flex; align-items: center; gap: 8pt; }
    .brand-mark {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26pt;
        height: 26pt;
        border-radius: 7pt;
        background: #008251;
    }
    .brand-logo { height: 26pt; width: auto; }
    .brand-name { font-size: 11pt; font-weight: 700; letter-spacing: -0.01em; }
    .brand-tagline { font-size: 7.5pt; color: #616A75; text-transform: uppercase; letter-spacing: 0.08em; }
    .masthead-meta { text-align: right; font-size: 7.5pt; color: #616A75; line-height: 1.5; }
    .masthead-meta strong { color: #121921; font-weight: 600; }

    /* Title */
    .title-block { margin: 16pt 0 10pt; }
    .eyebrow { font-size: 7.5pt; font-weight: 700; color: #008251; text-transform: uppercase; letter-spacing: 0.12em; }
    h1 { margin: 3pt 0 0; font-size: 20pt; line-height: 1.15; font-weight: 700; letter-spacing: -0.02em; }
    .subtitle { margin: 4pt 0 0; font-size: 9.5pt; color: #616A75; }

    .filters {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 4pt;
        margin: 0 0 14pt;
        padding: 7pt 9pt;
        border: 1px solid #E3E7EA;
        border-radius: 6pt;
        background: #F6F8F9;
        font-size: 8pt;
    }
    .filters-label { font-weight: 700; color: #616A75; text-transform: uppercase; letter-spacing: 0.08em; font-size: 7pt; margin-right: 4pt; }
    .chip { display: inline-block; padding: 1.5pt 7pt; border-radius: 999pt; background: #ffffff; border: 1px solid #DDE2E6; color: #121921; }

    /* KPI cards */
    .metrics { display: grid; gap: 8pt; margin-bottom: 14pt; }
    .metric {
        padding: 9pt 10pt 10pt;
        border: 1px solid #E3E7EA;
        border-top: 2.5pt solid #008251;
        border-radius: 6pt;
        background: #ffffff;
        break-inside: avoid;
    }
    .metric-label { font-size: 7pt; font-weight: 700; color: #616A75; text-transform: uppercase; letter-spacing: 0.08em; }
    .metric-value { margin-top: 4pt; font-size: 17pt; font-weight: 700; letter-spacing: -0.02em; line-height: 1.1; overflow-wrap: break-word; }
    .metric-value.is-text { font-size: 12pt; line-height: 1.25; }
    .metric-hint { margin-top: 3pt; font-size: 7.5pt; color: #616A75; }

    /* Charts */
    .charts { display: grid; grid-template-columns: 1.35fr 1fr; gap: 8pt; margin-bottom: 16pt; }
    .charts.single { grid-template-columns: 1fr; }
    .chart-card { padding: 10pt 12pt 12pt; border: 1px solid #E3E7EA; border-radius: 6pt; break-inside: avoid; }
    .chart-title { margin: 0; font-size: 9.5pt; font-weight: 700; }
    .chart-subtitle { margin: 1pt 0 8pt; font-size: 7.5pt; color: #616A75; }
    .chart-svg { display: block; overflow: visible; }
    .chart-label { font-size: 11px; fill: #121921; }
    .chart-value { font-size: 11px; font-weight: 600; fill: #121921; }
    .chart-axis { font-size: 10px; fill: #8A929C; }
    .legend { display: flex; flex-wrap: wrap; gap: 10pt; margin-top: 8pt; font-size: 7.5pt; color: #616A75; }
    .legend-item { display: inline-flex; align-items: center; }
    .legend-swatch { display: inline-block; width: 7pt; height: 7pt; border-radius: 2pt; margin-right: 5pt; vertical-align: -0.5pt; }
    .donut { display: flex; align-items: center; gap: 14pt; }
    .donut-svg { flex: none; }
    .donut-total { font-size: 20px; font-weight: 700; fill: #121921; }
    .donut-caption { font-size: 8px; fill: #616A75; text-transform: uppercase; letter-spacing: 0.08em; }
    .donut-legend { border-collapse: collapse; font-size: 8.5pt; flex: 1; }
    .donut-legend td { padding: 4pt 0 4pt 10pt; border-bottom: 1px solid #F0F2F4; white-space: nowrap; }
    .donut-legend td:first-child { padding-left: 0; }
    .donut-legend tr:last-child td { border-bottom: 0; }

    /* Record panels (label/value) */
    .panels { display: grid; grid-template-columns: 1fr 1fr; gap: 8pt; margin-bottom: 14pt; }
    .panels.single { grid-template-columns: 1fr; }
    .panel { padding: 9pt 11pt 10pt; border: 1px solid #E3E7EA; border-left: 2.5pt solid #008251; border-radius: 6pt; break-inside: avoid; }
    .panel-title { margin: 0 0 6pt; font-size: 9.5pt; font-weight: 700; }
    .panel dl { display: grid; grid-template-columns: minmax(0, 34%) minmax(0, 1fr); margin: 0; column-gap: 8pt; row-gap: 3pt; font-size: 8.5pt; }
    .panel dt { color: #616A75; overflow-wrap: break-word; }
    .panel dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
    .panel-note { margin: 7pt 0 0; font-size: 7.5pt; color: #616A75; }

    /* Extra sections (timeline, matrix…) */
    .section { margin-bottom: 16pt; }
    .section.new-page { break-before: page; }
    .section.keep { break-inside: avoid; }
    .section-description { margin: -2pt 0 6pt; font-size: 7.5pt; color: #616A75; }

    /* Data table */
    /* With a summary above, a long detail starts on its own page (no orphan heading);
       a short one stays with the summary and is kept in one piece. */
    .detail.after-summary { break-before: page; }
    .detail:not(.after-summary) { break-inside: avoid; }
    .section-head { display: flex; align-items: baseline; justify-content: space-between; margin: 0 0 6pt; }
    .section-title { margin: 0; font-size: 11pt; font-weight: 700; }
    .section-count { font-size: 8pt; color: #616A75; }

    table.data { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 8pt; }
    table.data thead { display: table-header-group; }
    table.data tfoot { display: table-row-group; }
    table.data tr { break-inside: avoid; page-break-inside: avoid; }
    table.data th {
        padding: 6pt 6pt;
        background: #008251;
        color: #ffffff;
        font-size: 7.5pt;
        font-weight: 700;
        line-height: 1.25;
        text-align: left;
        vertical-align: bottom;
        border-right: 1px solid #0A8F5C;
        overflow-wrap: break-word;
        hyphens: auto;
    }
    table.data th:last-child { border-right: 0; }
    table.data td { padding: 4pt 6pt; border-bottom: 1px solid #E3E7EA; vertical-align: top; overflow-wrap: break-word; }
    table.data tbody tr.row:nth-child(even) td { background: #F6F8F9; }
    table.data .align-right { text-align: right; }
    table.data .nowrap { white-space: nowrap; }
    table.data .datetime { overflow-wrap: normal; word-break: keep-all; }
    table.data .align-center { text-align: center; }
    table.data tr.group td { padding-top: 8pt; background: #ffffff; font-weight: 700; color: #006435; border-bottom: 1.5pt solid #008251; }
    table.data tr.subtotal td { background: #F6F8F9; font-weight: 600; color: #616A75; }
    table.data tr.totals td { background: #E6F5EE; font-weight: 700; border-top: 1.5pt solid #008251; border-bottom: 0; }
    .empty { padding: 22pt; text-align: center; color: #616A75; border: 1px dashed #C3CAD1; border-radius: 6pt; }

    .document-end { margin-top: 10pt; font-size: 7.5pt; color: #8A929C; }
</style>
