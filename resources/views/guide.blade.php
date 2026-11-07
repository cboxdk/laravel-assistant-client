<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ route('ai-assistant-connector.support-style') }}">
</head>
<body class="support-guide"><main><nav><a href="{{ url('/') }}">{{ config('app.name') }}</a></nav><article>{!! $html !!}</article></main></body>
</html>
