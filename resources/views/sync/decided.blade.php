@extends('statamic::layout')
@section('title', $title)

@section('content')
    <ui-header title="{{ $title }}" icon="key"></ui-header>

    <ui-card>
        <ui-description>{{ $approved ? __('core-web::sync.approved') : __('core-web::sync.denied') }}</ui-description>
    </ui-card>
@endsection
