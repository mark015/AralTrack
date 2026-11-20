<?php
/**
 * Start of a standalone printable page (DepEd forms).
 * @var string $printTitle
 * @var string $printBack   page to go back to
 * @var string $printNote   text in the toolbar
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($printTitle) ?></title>
    <style>
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 10pt/1.35 "Segoe UI", Arial, sans-serif; color: #000; background: #e9ecef; }
        .toolbar { position: sticky; top: 0; z-index: 2; padding: 10px 16px; background: #102452; color: #fff; font-size: 14px; display: flex; gap: 12px; align-items: center; }
        .toolbar button { font-size: 14px; padding: 4px 14px; border-radius: 6px; border: 0; background: #f5b82e; font-weight: 600; cursor: pointer; }
        .toolbar a { color: #fff; }
        .page { width: 210mm; min-height: 297mm; margin: 12px auto; padding: 12mm 13mm; background: #fff; box-shadow: 0 2px 12px rgba(0,0,0,.15); page-break-after: always; break-after: page; }
        .page:last-child { page-break-after: auto; break-after: auto; }
        .deped { display: flex; align-items: center; justify-content: center; gap: 12px; text-align: center; margin-bottom: 6px; }
        .deped .lines { line-height: 1.25; }
        .deped .lines div:first-child { font-size: 9pt; }
        .deped .school { font-weight: 700; font-size: 11pt; }
        h1 { font-size: 13pt; text-align: center; margin: 6px 0 10px; letter-spacing: .03em; }
        h2 { font-size: 10.5pt; margin: 12px 0 4px; text-transform: uppercase; letter-spacing: .04em; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #444; padding: 3px 5px; vertical-align: middle; }
        th { background: #eef1f7; font-size: 8.5pt; }
        td.c, th.c { text-align: center; }
        .info { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 18px; margin: 6px 0 10px; }
        .info div { border-bottom: 1px solid #999; padding: 2px 0; }
        .info span { color: #555; font-size: 8.5pt; margin-right: 6px; }
        .group td { background: #f6f7fa; font-weight: 600; font-size: 8.5pt; }
        .sign { display: flex; justify-content: space-around; margin-top: 18px; text-align: center; }
        .sign div { width: 40%; border-top: 1px solid #000; padding-top: 2px; font-size: 9pt; }
        .small { font-size: 8.5pt; color: #333; }
        .legend { display: flex; gap: 18px; margin-top: 8px; }
        .legend table td, .legend table th { font-size: 8pt; padding: 1px 4px; }
        @media print { body { background: #fff; } .toolbar { display: none; } .page { margin: 0; box-shadow: none; width: auto; min-height: 0; padding: 0; } }
    </style>
</head>
<body>
<div class="toolbar">
    <button onclick="window.print()">Print</button>
    <a href="<?= e(url($printBack)) ?>">Back</a>
    <span><?= e($printNote) ?></span>
</div>
