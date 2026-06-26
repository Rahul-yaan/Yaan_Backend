<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; background: #f4f4f4; padding: 24px;">
  <div style="max-width: 480px; margin: auto; background: white; border-radius: 12px; padding: 32px;">
    <h2 style="color: #C0392B;">Reset Your Password</h2>
    <p>Hi {{ $user->name }},</p>
    <p>Click the button below to reset your StayEase password. This link expires in <strong>3 minutes</strong>.</p>
    <a href="{{ $resetLink }}"
       style="display:inline-block; background:#C0392B; color:white;
              padding:12px 24px; border-radius:8px; text-decoration:none;
              font-weight:600; margin: 16px 0;">
      Reset Password
    </a>
    <p style="color:#888; font-size:12px;">If you didn't request this, ignore this email.</p>
  </div>
</body>
</html>