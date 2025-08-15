@extends('layouts.app')

@section('content')
  <div id="photobook-root" style="height: calc(100vh - 0px); background:#fafafa;"></div>
@endsection

@push('scripts')
  @vite('resources/photobook-editor/main.tsx')
@endpush