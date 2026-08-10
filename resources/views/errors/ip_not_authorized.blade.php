<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>403 - Not Authorized</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: #f7f7fa;
            color: #1f2430;
            font-family: Arial, Helvetica, sans-serif;
        }
        .card {
            width: 100%;
            max-width: 560px;
            padding: 40px;
            text-align: center;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 12px 32px rgba(31, 36, 48, .08);
        }
        .code { margin: 0 0 8px; color: #6f2da8; font-size: 56px; line-height: 1; }
        h1 { margin: 0 0 12px; font-size: 28px; }
        p { margin: 0 0 22px; color: #667085; line-height: 1.6; }
        .ip {
            display: inline-block;
            margin-bottom: 26px;
            padding: 10px 14px;
            color: #344054;
            background: #f2f4f7;
            border-radius: 6px;
            font-family: Consolas, monospace;
        }
        button {
            padding: 10px 22px;
            color: #fff;
            background: #6f2da8;
            border: 0;
            border-radius: 6px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="code">403</div>
        <h1>Not Authorized</h1>
        <p>Your account is signed in, but access is not allowed from the current IP address.</p>
        <div class="ip">Detected IP: {{ $ipAddress ?: 'Unavailable' }}</div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Sign out</button>
        </form>
    </main>
</body>
</html>
