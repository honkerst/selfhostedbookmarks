<?php
/**
 * Web-based password hash generator
 * Access this file through your web browser after starting your web server
 * Example: http://localhost:8000/setup-password-web.php
 */

// Simple security - delete this file after use!
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Setup - Pinboard Clone</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2563eb;
            margin-bottom: 10px;
        }
        .warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .warning strong {
            color: #92400e;
        }
        form {
            margin: 20px 0;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #374151;
        }
        input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 16px;
            box-sizing: border-box;
        }
        button {
            background: #2563eb;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 10px;
        }
        button:hover {
            background: #1d4ed8;
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            background: #f3f4f6;
            border-radius: 6px;
            font-family: 'Monaco', 'Courier New', monospace;
            word-break: break-all;
        }
        .result strong {
            display: block;
            margin-bottom: 10px;
            color: #374151;
        }
        .code-block {
            background: #1f2937;
            color: #10b981;
            padding: 15px;
            border-radius: 6px;
            margin: 10px 0;
            overflow-x: auto;
        }
        .info {
            background: #eff6ff;
            border-left: 4px solid #3b82f6;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
            color: #1e40af;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 Password Setup</h1>
        <p>Generate a password hash for your Pinboard Clone installation.</p>
        
        <div class="warning">
            <strong>⚠️ Security Warning:</strong> Delete this file after you've set up your password!
        </div>
        
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
            $password = $_POST['password'];
            
            if (empty($password)) {
                echo '<div class="warning">Please enter a password.</div>';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                
                echo '<div class="result">';
                echo '<strong>✓ Password hash generated:</strong>';
                echo '<div class="code-block">' . htmlspecialchars($hash) . '</div>';
                echo '</div>';
                
                echo '<div class="info">';
                echo '<strong>Next steps - Choose one method:</strong><br><br>';
                echo '<strong>Method 1: Environment Variable (Recommended for production)</strong><br>';
                echo 'Set as environment variable in your web server configuration:<br>';
                echo '<div class="code-block">export PINBOARD_PASSWORD_HASH=\'' . htmlspecialchars($hash) . '\'</div>';
                echo '<br>';
                echo '<strong>Method 2: Config File</strong><br>';
                echo '1. Open <code>includes/config.php</code><br>';
                echo '2. Find the PASSWORD_HASH section and uncomment/update:<br>';
                echo '<div class="code-block">define(\'PASSWORD_HASH\', \'' . htmlspecialchars($hash) . '\');</div>';
                echo '<br>';
                echo '3. <strong>Delete this file</strong> for security!';
                echo '</div>';
            }
        }
        ?>
        
        <form method="POST">
            <label for="password">Enter your password:</label>
            <input type="password" id="password" name="password" required autofocus>
            <button type="submit">Generate Hash</button>
        </form>
        
        <div class="info">
            <strong>Alternative method:</strong><br>
            If PHP is installed but not in your PATH, you can also run:<br>
            <div class="code-block">/usr/bin/php setup-password.php your_password<br>
# or<br>
/opt/homebrew/bin/php setup-password.php your_password</div>
        </div>
    </div>
</body>
</html>

