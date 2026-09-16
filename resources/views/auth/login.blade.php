<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingresar — Gestión de Obras</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #F3F4F6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-card {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            padding: 2rem;
            width: 100%;
            max-width: 400px;
        }

        .logo-mark {
            display: flex;
            justify-content: center;
            margin-bottom: 1.5rem;
        }

        .logo-mark img {
            height: 64px;
            width: auto;
            object-fit: contain;
            border-radius: 8px;
        }

        h1 {
            font-size: 20px;
            font-weight: 600;
            color: #3D3D3D;
            margin-bottom: 4px;
            text-align: center;
        }

        .subtitle {
            font-size: 13px;
            color: #6B7280;
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .field { margin-bottom: 1rem; }

        .field label {
            font-size: 12px;
            font-weight: 500;
            color: #6B7280;
            display: block;
            margin-bottom: 5px;
        }

        .field input {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            font-size: 14px;
            color: #3D3D3D;
            transition: border-color .15s;
        }

        .field input:focus {
            outline: none;
            border-color: #2563B0;
            box-shadow: 0 0 0 3px rgba(37,99,176,.12);
        }

        .btn-login {
            width: 100%;
            padding: 10px;
            background: #1B3F6E;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background .15s;
            margin-bottom: 1rem;
        }

        .btn-login:hover { background: #2563B0; }

        .forgot {
            font-size: 12px;
            color: #2563B0;
            text-decoration: none;
            display: block;
            text-align: center;
        }

        .forgot:hover { text-decoration: underline; }

        .alert-error {
            background: #FEF2F2;
            color: #DC2626;
            border: 1px solid #FECACA;
            border-radius: 8px;
            padding: 9px 12px;
            font-size: 13px;
            margin-bottom: 1rem;
        }

        .alert-info {
            background: #EFF6FF;
            color: #1B3F6E;
            border: 1px solid #BFDBFE;
            border-radius: 8px;
            padding: 9px 12px;
            font-size: 13px;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="login-card">

        <div class="logo-mark">
            <img src="/images/logo-secar.JPG" alt="Secar Ingenieros">
        </div>
        <h1>Bienvenido</h1>
        <p class="subtitle">Gestión Financiera de Proyectos</p>

        @if (session('status'))
            <div class="alert-info">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="alert-error">
                Correo o contraseña incorrectos
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div class="field">
                <label for="email">Correo electrónico</label>
                <input id="email" type="email" name="email"
                    value="{{ old('email') }}"
                    required autofocus
                    placeholder="usuario@empresa.com">
            </div>
            <div class="field">
                <label for="password">Contraseña</label>
                <input id="password" type="password"
                    name="password" required
                    placeholder="••••••••">
            </div>
            <button type="submit" class="btn-login">Ingresar</button>
        </form>

        <a href="{{ route('password.request') }}" class="forgot">
            ¿Olvidaste tu contraseña?
        </a>

    </div>
</body>
</html>