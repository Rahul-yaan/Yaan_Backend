<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password — Yaan</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: sans-serif; background: #f4f4f4; padding: 24px; }
        .card { max-width: 420px; margin: 60px auto; background: white; border-radius: 16px; padding: 32px; box-shadow: 0 2px 12px rgba(0,0,0,0.1); }
        h2 { color: #C0392B; margin-bottom: 8px; }
        p { color: #888; font-size: 14px; margin-bottom: 24px; }
        input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; margin-bottom: 16px; }
        button { width: 100%; padding: 14px; background: #C0392B; color: white; border: none; border-radius: 8px; font-size: 16px; cursor: pointer; }
        button:hover { background: #a93226; }
        .error { color: #C0392B; font-size: 13px; margin-bottom: 12px; }
        .success { color: #27AE60; font-size: 13px; margin-bottom: 12px; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Reset Password</h2>
        <p>Enter your new password below.</p>
        <div id="message"></div>
        <input type="hidden" id="token" value="{{ request('token') }}">
        <input type="hidden" id="email" value="{{ request('email') }}">
        <input type="password" id="password" placeholder="New Password" minlength="6">
        <input type="password" id="confirm" placeholder="Confirm Password">
        <button onclick="resetPassword()">Reset Password</button>
    </div>

    <script>
        async function resetPassword() {
            const token = document.getElementById('token').value;
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm').value;
            const msg = document.getElementById('message');

            if (password.length < 6) {
                msg.innerHTML = '<p class="error">Password must be at least 6 characters.</p>';
                return;
            }
            if (password !== confirm) {
                msg.innerHTML = '<p class="error">Passwords do not match.</p>';
                return;
            }

            try {
                const res = await fetch('/api/reset-password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        token,
                        email,
                        password,
                        password_confirmation: confirm
                    })
                });
                const data = await res.json();
                if (data.message) {
                    msg.innerHTML = '<p class="success">✓ ' + data.message + '</p>';
                } else {
                    msg.innerHTML = '<p class="error">' + (data.error || 'Something went wrong.') + '</p>';
                }
            } catch (e) {
                msg.innerHTML = '<p class="error">Network error. Please try again.</p>';
            }
        }
    </script>
</body>
</html>