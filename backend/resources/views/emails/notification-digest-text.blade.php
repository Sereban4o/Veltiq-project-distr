@php
    $title = $frequency === 'weekly' ? 'Недельный дайджест уведомлений' : 'Дайджест уведомлений';
@endphp
{{ $title }}

Здравствуйте, {{ $user->first_name }}!

У вас {{ count($rows) }} новых уведомлений:

@foreach($rows as $row)
- [{{ $row['type'] }}] {{ $row['summary'] }} ({{ $row['created_at'] }})
@endforeach

Настроить уведомления можно в разделе «Уведомления» вашего профиля.
