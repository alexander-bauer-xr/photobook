@extends('layouts.app')

@section('content')
  <div id="photobook-root" class="min-h-screen bg-neutral-50"></div>
@endsection

@push('scripts')
  @vite('resources/photobook-editor/main.tsx')
@endpush