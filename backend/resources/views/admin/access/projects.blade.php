@extends('admin.layout')

@section('title', 'Доступ к проектам')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <h1 style="color: #172B4D;">🔐 Доступ к проектам</h1>
    <a href="{{ route('admin.access.spaces') }}" class="btn btn-secondary btn-sm">Перейти к пространствам →</a>
</div>

@include('admin.access._content')
@endsection
