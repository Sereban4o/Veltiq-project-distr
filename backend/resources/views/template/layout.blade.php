<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', $template->name ?? config('app.name'))</title>
    @if(! empty($templateData['branding']['favicon']))
        <link rel="icon" href="{{ $templateData['branding']['favicon'] }}">
    @endif
    @if(! empty($stylesheetUrl))
        <link rel="stylesheet" href="{{ $stylesheetUrl }}">
    @endif
    @stack('styles')
</head>
<body>
@yield('content')
<script>
    window.TEMPLATE_DATA = @json($templateData);
</script>
@if(! empty($scriptUrl))
    <script src="{{ $scriptUrl }}"></script>
@endif
@stack('scripts')
</body>
</html>