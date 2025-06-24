<?php
function getConnection()
{
    $host = "127.0.0.1";
    $user = "sms2";
    $pass = "RingoVas1@#$";
    $db = "uba";

    $conn = new mysqli($host, $user, $pass, $db);

    if ($conn->connect_error) {
	var_dump($conn->connect_error);
        die("Connection failed: " . $conn->connect_error);
    }

    return $conn;
}

function closeConnection($conn)
{
    if ($conn) {
        $conn->close();
    }
}
