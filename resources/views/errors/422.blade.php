{{--
    Lo que se pidió no se puede hacer así, y el mensaje dice por qué: lo
    usan las páginas que se abren por enlace —un reporte impreso con un
    período de más de un año—, donde no hay un formulario que muestre el
    error al lado del campo.
--}}
@extends('errors.layout')

@section('codigo', '422')
@section('titulo', 'No se puede mostrar así')
@section('mensaje', isset($exception) && $exception->getMessage() !== '' ? $exception->getMessage() : 'Revisá los datos que se pidieron y volvé a intentar.')

@section('acciones')
    <a href="javascript:history.back()" class="btn btn-primary">Volver atrás</a>
@endsection
