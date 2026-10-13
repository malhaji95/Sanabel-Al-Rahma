@extends('layouts.app')

@section('title', __('sanabel.errors.404_title'))

@section('content')
    <x-errors.card
        icon="info"
        :title="__('sanabel.errors.404_title')"
        :body="__('sanabel.errors.404_body')" />
@endsection
