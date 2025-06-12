<?php
$password = "OdH8K4c98d!";  // Replace with your desired password
$hashed = password_hash($password, PASSWORD_DEFAULT);
echo "Hashed password: " . $hashed;
