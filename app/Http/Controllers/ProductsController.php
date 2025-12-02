<?php

namespace App\Http\Controllers;

use App\Models\Products;
use Illuminate\Http\Request;

class ProductsController extends Controller
{
    public function update(Request $request, Products $product)
    {
        $product->update($request->only('serial')); // o los campos que permitas modificar
        return back();
    }

    /**
     * Genera un número de serie único de 9 caracteres: LNNNNNNNL
     */
    static function Serial()
    {
        do {
            $serial = ProductsController::randomSerial();
        } while (Products::where('serial', $serial)->exists());

        return $serial;
    }

    /**
     * Genera un número de serie aleatorio
     */
    static function randomSerial()
    {
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $numbers = '0123456789';

        $first = $letters[random_int(0, 25)];
        $last  = $letters[random_int(0, 25)];

        $middle = '';
        for ($i = 0; $i < 7; $i++) {
            $middle .= $numbers[random_int(0, 9)];
        }

        return $first . $middle . $last;
    }
}
