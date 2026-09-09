@extends('errors::minimal')

@section('title', __('Sem permissão'))
@section('code', '403')
@section('message', $exception?->getMessage() ?: __('Esta ação não está disponível para o estado atual do registro.'))
