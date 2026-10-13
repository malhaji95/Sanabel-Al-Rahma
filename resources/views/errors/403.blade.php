@extends('layouts.app')

@section('title', __('sanabel.errors.403_title'))

@section('content')
    <x-errors.card
        icon="lock"
        :title="__('sanabel.errors.403_title')"
        :body="__('sanabel.errors.403_body')" />
@endsection
