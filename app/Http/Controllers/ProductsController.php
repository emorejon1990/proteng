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
}
