<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Redirect if already logged in
if (isAuthenticated()) {
    header('Location: /index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    
    if (empty($password)) {
        $error = 'Password is required';
    } elseif (login($password)) {
        header('Location: /index.php');
        exit;
    } else {
        $error = 'Invalid password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <h1><?php echo h(SITE_NAME); ?></h1>
            <form method="POST" action="/login.php" id="login-form" autocomplete="on">
                <?php if ($error): ?>
                    <div class="error-message"><?php echo h($error); ?></div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="username">Account (optional)</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        autocomplete="username"
                        placeholder="Leave blank if not used">
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        autocomplete="current-password"
                        required
                        autofocus>
                </div>
                
                <button type="submit" class="btn btn-primary">Login</button>
            </form>
        </div>
    </div>
</body>
</html>

