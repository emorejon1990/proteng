<!doctype html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <title>Access created</title>
    </head>
    <body style="font-family: Arial, sans-serif; color: #111827; line-height: 1.5;">
        <div style="max-width: 600px; margin: 0 auto; padding: 24px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <img src="{{ asset('proteng.png') }}" alt="{{ config('app.name') }}" style="height: 40px;">
                <strong style="font-size: 18px;">{{ config('app.name') }}</strong>
            </div>

            <p style="margin-top: 24px;">
                Good Day {{ $user->name }},
            </p>

            <p>
                We are pleased to inform you that your user account has been created in our application. Your login credentials are provided below.
            </p>

            <div style="background: #f3f4f6; padding: 16px; border-radius: 8px;">
                <p style="margin: 0 0 8px 0;"><strong>User:</strong> {{ $user->email }}</p>
                <p style="margin: 0;"><strong>Password:</strong> {{ $password }}</p>
            </div>

            <p style="margin-top: 16px;">
                For security, you should change your password after your first authentication.
            </p>

            <p style="margin-top: 24px;">
                Greetings,<br>
                {{ config('app.name') }}
            </p>
        </div>
    </body>
</html>
