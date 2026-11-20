@extends('admin.layout')

@section('title', 'Доступ к пространствам')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <h1 style="color: #172B4D;">🔐 Доступ к пространствам</h1>
    <a href="{{ route('admin.access.projects') }}" class="btn btn-secondary btn-sm">← Перейти к проектам</a>
</div>

@include('admin.access._content')
@endsection
