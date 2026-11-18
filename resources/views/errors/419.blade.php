@extends('errors.layout')

@section('title', 'Sesión expirada')
@section('code', '419')
@section('message', 'Tu sesión expiró. Vuelve a cargar la página e inténtalo de nuevo.')
@section('action', 'Volver a cargar')
@section('action-href', url()->previous())
@section('reload', '1')
