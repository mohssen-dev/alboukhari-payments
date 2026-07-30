@extends('layouts.app')

@section('content')
    @livewire('students-grid', ['focus' => true])
@endsection
