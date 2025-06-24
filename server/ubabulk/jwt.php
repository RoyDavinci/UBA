<?php
require_once 'vendor/autoload.php';  // If you're using Composer for Firebase JWT

use \Firebase\JWT\JWT;
use Firebase\JWT\Key;


function generateJWT($payload)
{
    $secretKey = "your-secret-key";  // Set your secret key
    $algorithm = 'HS256';           // Specify the JWT algorithm

    // Encode the payload to create the token
    return JWT::encode($payload, $secretKey, $algorithm);
}

function decodeJWT($jwt)
{
    $secretKey = "your-secret-key";  // Set your secret key

    try {
        $decoded = JWT::decode($jwt, new Key($secretKey, 'HS256'));
        return (array) $decoded;
    } catch (Exception $e) {
        return null;
    }
}
