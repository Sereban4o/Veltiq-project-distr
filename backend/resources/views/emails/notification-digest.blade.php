@php
    $title = $frequency === 'weekly' ? 'Недельный дайджест уведомлений' : 'Дайджест уведомлений';
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
</head>
<body style="margin:0; padding:0; background:#f5f7fa; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color:#333;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:8px; overflow:hidden;">
                    <tr>
                        <td style="background:#0052CC; color:#ffffff; padding:20px 24px; font-size:18px; font-weight:600;">
                            {{ $title }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin:0 0 16px;">Здравствуйте, {{ $user->first_name }}!</p>
                            <p style="margin:0 0 20px; color:#6B778C; font-size:14px;">У вас {{ count($rows) }} новых уведомлений.</p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                @foreach($rows as $row)
                                    <tr>
                                        <td style="padding:12px 0; border-bottom:1px solid #EBECF0;">
                                            <div style="font-size:12px; color:#0052CC; font-weight:600;">{{ $row['type'] }}</div>
                                            <div style="margin:4px 0;">{{ $row['summary'] }}</div>
                                            <div style="font-size:12px; color:#6B778C;">{{ $row['created_at'] }}</div>
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px; color:#6B778C; font-size:12px; border-top:1px solid #EBECF0;">
                            Настроить уведомления можно в разделе «Уведомления» вашего профиля.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
