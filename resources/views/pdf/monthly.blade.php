<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Lateness and Client Performance Report by Employee</title>
<style>
    /* UX5-08: sizes in pt, matched to docs/modeles/Rapport_de_performance_Octobre_2026.pdf (A4, Calibri 11 pt body,
       13 pt header and title; DejaVu Sans is wider than Calibri, hence 10.5 pt in the table). */
    @page { margin: 72pt 70pt 60pt 70pt; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5pt; color: #000; }
    .brand { position: fixed; top: -34pt; left: 0; line-height: 1.2; }
    .brand .name { color: #4472C4; font-size: 13pt; }
    .brand .by { color: #ED7D31; font-size: 13pt; padding-left: 14pt; }
    h1 { text-align: center; font-size: 13pt; font-weight: bold; margin: 78pt 0 30pt 0; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 0.75pt solid #000; text-align: center; vertical-align: middle; padding: 5pt 3pt; }
    th { font-weight: bold; font-size: 11pt; }
    td.late { color: #FF0000; font-weight: bold; }
    td.num, td.services { font-weight: bold; }
    .detail { display: block; font-size: 8.5pt; color: #444; }
    footer { position: fixed; bottom: -32pt; left: 0; right: 0; text-align: center; font-size: 10pt; }
</style>
</head>
<body>
<div class="brand"><div class="name">ASSISTANCE GHANA</div><div class="by">by GIMA Services</div></div>
<footer>Monthly Performance Report by Employee</footer>
<h1>Lateness and Client Performance Report by Employee</h1>
<table>
    <thead>
    <tr><th style="width:26%">Employee</th><th style="width:14%">Late arrivals</th><th style="width:22%">Clients</th><th style="width:38%">Client services</th></tr>
    </thead>
    <tbody>
    @foreach ($rows as $row)
        <tr>
            <td>{{ $row['employee'] }}@if (! empty($row['detail']))<span class="detail">{{ $row['detail'] }}</span>@endif</td>
            <td class="late">{{ $row['late'] }}</td>
            <td class="num">{{ \App\Domain\Reports\MonthlyReportService::twoDigits($row['clients']) }}</td>
            <td class="services">
                @forelse ($row['services'] as $service)
                    {{ \App\Domain\Reports\MonthlyReportService::twoDigits($service['quantity']) }} {{ $service['service'] }}@if (! $loop->last)<br>@endif
                @empty
                    -
                @endforelse
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
