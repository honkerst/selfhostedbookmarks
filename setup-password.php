<?php
/**
 * Helper script to generate password hash
 * Run this from command line: php setup-password.php
 */

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

echo "Pinboard Clone - Password Setup\n";
echo "===============================\n\n";

if ($argc < 2) {
    echo "Usage: php setup-password.php <your_password>\n\n";
    echo "This will generate a password hash that you can use in includes/config.php\n";
    echo "or set as the PINBOARD_PASSWORD_HASH environment variable.\n\n";
    exit(1);
}

$password = $argv[1];
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Password hash generated:\n";
echo $hash . "\n\n";
echo "Add this to includes/config.php:\n";
echo "define('PASSWORD_HASH', '" . $hash . "');\n\n";
echo "Or set as environment variable:\n";
echo "export PINBOARD_PASSWORD_HASH='" . $hash . "'\n\n";

