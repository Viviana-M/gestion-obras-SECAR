<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablece tu contraseña</title>
</head>
<body style="margin:0;padding:0;background:#F3F4F6;font-family:'Segoe UI',Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F4F6;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="440" cellpadding="0" cellspacing="0" style="background:#FFFFFF;border:1px solid #E5E7EB;border-radius:12px;overflow:hidden;max-width:440px;width:100%;">

                    {{-- Encabezado con logo de Secar --}}
                    <tr>
                        <td style="background:#1B3F6E;padding:24px;text-align:center;">
                            <img src="{{ $message->embed(public_path('images/logo-secar.JPG')) }}"
                                alt="Secar Ingenieros" style="height:56px;width:auto;border-radius:6px;">
                        </td>
                    </tr>

                    {{-- Cuerpo del mensaje --}}
                    <tr>
                        <td style="padding:28px 32px;color:#3D3D3D;">
                            <h1 style="font-size:19px;font-weight:600;color:#1B3F6E;margin:0 0 14px;">Restablece tu contraseña</h1>
                            <p style="font-size:14px;line-height:1.6;margin:0 0 14px;">Hola {{ $nombre }},</p>
                            <p style="font-size:14px;line-height:1.6;margin:0 0 20px;">
                                Recibimos una solicitud para restablecer la contraseña de tu cuenta en el sistema de
                                Gestión Financiera de Proyectos. Haz clic en el botón para crear una nueva contraseña:
                            </p>

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 22px;">
                                <tr>
                                    <td style="border-radius:8px;background:#1B3F6E;">
                                        <a href="{{ $url }}" style="display:inline-block;padding:11px 26px;font-size:14px;font-weight:500;color:#FFFFFF;text-decoration:none;border-radius:8px;">
                                            Restablecer contraseña
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size:13px;line-height:1.6;color:#6B7280;margin:0 0 14px;">
                                Este enlace vence en 60 minutos. Si tú no solicitaste este cambio, puedes ignorar este
                                correo; tu contraseña seguirá igual.
                            </p>
                            <p style="font-size:12px;line-height:1.6;color:#9CA3AF;margin:18px 0 0;border-top:1px solid #E5E7EB;padding-top:14px;">
                                Si el botón no funciona, copia y pega este enlace en tu navegador:<br>
                                <span style="color:#2563B0;word-break:break-all;">{{ $url }}</span>
                            </p>
                        </td>
                    </tr>

                    {{-- Pie --}}
                    <tr>
                        <td style="background:#F9FAFB;padding:16px 32px;text-align:center;border-top:1px solid #E5E7EB;">
                            <p style="font-size:11px;color:#9CA3AF;margin:0;">Secar Ingenieros · Gestión Financiera de Proyectos</p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>