{{ $greeting }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
@if ($highlight)
{{ $highlight }}

@endif
@if ($actionUrl)
{{ $actionText }}: {{ $actionUrl }}

@endif
@foreach ($outro as $line)
{{ $line }}
@endforeach

Assistance Ghana Attendance
