@extends('layouts.app')

@section('title', __('sanabel.errors.419_title'))

@section('content')
    <x-errors.card
        icon="info"
        :title="__('sanabel.errors.419_title')"
        :body="__('sanabel.errors.419_body')" />
@endsection
