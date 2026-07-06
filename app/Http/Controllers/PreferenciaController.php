<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;

class PreferenciaController extends Controller
{
    public function guardarMenu(Request $request)
    {
        $request->validate(['colapsado' => 'required|boolean']);

        /** @var User $user */
        $user = $request->user();
        $user->update(['menu_colapsado' => $request->boolean('colapsado')]);

        return response()->json(['ok' => true]);
    }
}