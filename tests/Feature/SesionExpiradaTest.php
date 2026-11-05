<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Al expirar la sesión / el token (error 419), el usuario debe ver un aviso claro
 * y volver al login, no la pantalla cruda "419 | PAGE EXPIRED".
 */
class SesionExpiradaTest extends TestCase
{
    #[Test]
    public function un_419_en_una_pagina_redirige_al_login_con_aviso(): void
    {
        $handler  = app(ExceptionHandler::class);
        $request  = Request::create('/login', 'POST', ['email' => 'a@b.co', 'password' => 'secreto']);
        $request->setLaravelSession(app('session.store'));

        $response = $handler->render($request, new TokenMismatchException());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('login'), $response->headers->get('Location'));
        // No debe reenviar la contraseña como old input.
        $this->assertArrayNotHasKey('password', $response->getSession()->getOldInput());
    }

    #[Test]
    public function un_419_en_una_peticion_json_responde_419_con_mensaje(): void
    {
        $handler  = app(ExceptionHandler::class);
        $request  = Request::create('/operativo/distribucion/guardar', 'POST', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response = $handler->render($request, new TokenMismatchException());

        $this->assertSame(419, $response->getStatusCode());
        $mensaje = json_decode($response->getContent(), true)['message'] ?? '';
        $this->assertStringContainsString('sesión expiró', $mensaje);
    }

    #[Test]
    public function el_login_muestra_el_aviso_de_sesion_expirada(): void
    {
        $this->withSession(['status' => 'Tu sesión expiró por inactividad. Por favor inicia sesión de nuevo.'])
            ->get(route('login'))
            ->assertStatus(200)
            ->assertSee('Tu sesión expiró por inactividad', false);
    }
}
