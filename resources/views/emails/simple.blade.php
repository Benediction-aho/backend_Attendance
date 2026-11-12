<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $subjectLine }}</title></head>
<body style="margin:0;padding:24px;background:#f5f7fa;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;padding:32px;">
    <tr><td>
      <p style="font-size:18px;font-weight:bold;margin:0 0 16px;">{{ $greeting }}</p>
      @foreach ($lines as $line)
        <p style="font-size:15px;line-height:1.5;margin:0 0 12px;">{{ $line }}</p>
      @endforeach
      @if ($highlight)
        <p style="font-size:24px;letter-spacing:3px;font-weight:bold;background:#eef2f7;border-radius:8px;padding:12px 16px;text-align:center;margin:16px 0;">{{ $highlight }}</p>
      @endif
      @if ($actionUrl)
        <p style="margin:20px 0;"><a href="{{ $actionUrl }}" style="background:#1f6feb;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:8px;display:inline-block;">{{ $actionText }}</a></p>
        <p style="font-size:12px;color:#6b7280;word-break:break-all;">{{ $actionUrl }}</p>
      @endif
      @foreach ($outro as $line)
        <p style="font-size:13px;line-height:1.5;color:#6b7280;margin:12px 0 0;">{{ $line }}</p>
      @endforeach
      <p style="font-size:13px;color:#6b7280;margin:24px 0 0;">Assistance Ghana Attendance</p>
    </td></tr>
  </table>
</body>
</html>
