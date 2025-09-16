<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use Illuminate\Http\Request;

class WorkOrderController extends Controller
{
    static function WOname($prefijo)
    {
        // Buscar el último registro con ese prefijo
        $ultimo = WorkOrder::where('name', 'like', $prefijo . '%')
            ->orderBy('name', 'desc')
            ->first();

        if ($ultimo) {
            // dump($ultimo);
            // Extraer solo la parte numérica
            $numero = (int) substr($ultimo->name, strlen($prefijo));
            // dd($numero);
            $nuevoNumero = $numero + 1;
        } else {
            // Si no hay registros con ese prefijo, arrancamos en 1
            $nuevoNumero = 1;
        }

        // Formatear con ceros a la izquierda (ej: 001, 002, 003)
        $nuevoCodigo = $prefijo . $nuevoNumero;

        // // Guardar en la base de datos
        // $item = new WorkOrder();
        // $item->name = $nuevoCodigo;
        // $item->save();

        return  $nuevoCodigo;
    }
}
