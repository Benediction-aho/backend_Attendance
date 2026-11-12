<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Lateness and Client Performance Report by Employee</title>
<style>
    @page { margin: 70px 85px 70px 85px; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #000; }
    .brand { position: fixed; top: -40px; left: -30px; line-height: 1.25; }
    .brand .name { color: #4472C4; font-size: 13px; }
    .brand .by { color: #ED7D31; font-size: 13px; padding-left: 18px; }
    h1 { text-align: center; font-size: 13px; font-weight: bold; margin: 90px 0 30px 0; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #000; text-align: center; vertical-align: middle; padding: 6px 4px; }
    th { font-weight: bold; }
    td.late { color: #FF0000; font-weight: bold; }
    td.num, td.services { font-weight: bold; }
    footer { position: fixed; bottom: -30px; left: 0; right: 0; text-align: center; font-size: 10px; }
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
            <td>{{ $row['employee'] }}</td>
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
