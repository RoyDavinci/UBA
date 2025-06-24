<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

require './conn.php';
require './log.php';
require './jwt.php';

$conn = getConnection();
log_action("=== LOGIN ATTEMPT START ===");

// Read input (JSON or URL-encoded)
$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    $data = $_REQUEST;
}
log_action("Raw Input Data: " . json_encode($data));

// Sanitize input
$email = trim($conn->real_escape_string($data['email'] ?? ''));
$password = $data['password'] ?? '';

// Validation: Check if email and password are provided
if (!$email || !$password) {
    log_action("Validation failed: Missing email or password.");
    echo json_encode(["status" => false, "message" => "Email and password are required."]);
    closeConnection($conn);
    exit;
}

$sql = "SELECT id, full_name, email, password, role FROM users WHERE email = '$email'";
log_action("Executing SQL query: $sql");

$result = $conn->query($sql);

if (!$result) {
    log_action("Query failed: " . $conn->error);
    echo json_encode(["status" => false, "message" => "Database error occurred."]);
    closeConnection($conn);
    exit;
}

log_action("Query executed. Rows found: " . $result->num_rows);

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();
    log_action("User record fetched for: $email");

    if (password_verify($password, $user['password'])) {
        log_action("Password verified successfully for user ID: {$user['id']}");

        $otp = generateRandomNumbersString();
        $emailSent = sendMail($otp, $user['email']);

        log_action("OTP generated: $otp, sending email to: {$user['email']}");

        if ($emailSent) {
            log_action("OTP email sent successfully to: {$user['email']}");
            $query = "UPDATE users SET otp = '$otp' WHERE email = '$email'";
	    //            $result = $conn->query($sql);
	    $updateResult = $conn->query($query); // ✅ Correct one
if ($updateResult) {
    log_action("OTP updated successfully for: {$user['email']}");
} else {
    log_action("Failed to update OTP for: {$user['email']} | Error: " . $conn->error);
}

        } else {
            log_action("Failed to send OTP email to: {$user['email']}");
        }

	 $payload = [
        'id' => $user['id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'iat' => time(),
        'exp' => time() + 360000000000
    	];
	    $jwt = generateJWT($payload);
    log_action("JWT generated for user ID: {$row['id']}");
        echo json_encode([
            "status" => true,
	    "message" => "Login successful.",
	     "token" => $jwt,
        ]);
    } else {
        log_action("Password mismatch for email: $email");
        echo json_encode(["status" => false, "message" => "Invalid email or password."]);
    }
} else {
    log_action("Login failed: No matching user for email: $email");
    echo json_encode(["status" => false, "message" => "Invalid email or password."]);
}

closeConnection($conn);
log_action("=== LOGIN ATTEMPT END ===");


// Helper: Send OTP Mail

function sendMail($otp, $email)
{
    require_once './PHPMailer/PHPMailer.php';
    require_once './PHPMailer/SMTP.php';
    require_once './PHPMailer/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'email-smtp.us-east-1.amazonaws.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'AKIAX7YTA767FYW7IXJO';
        $mail->Password   = 'BHcB+rfmYUOHkYwa94z3t/BdUM+7VmVD4ux8Eo14x/js';
        $mail->SMTPSecure = 'tls'; // use 'ssl' if using port 465
        $mail->Port       = 587;

        // Sender and recipient
        $mail->setFrom('mail@redtechlimited.com', 'RedTech');
        $mail->addAddress($email); // recipient address

        // Email content
        $mail->isHTML(true);
        $mail->Subject = '🔐 Login OTP';
        $mail->Body    = "
            <html><body style='font-family: Arial, sans-serif; background-color: #f9f9f9; padding: 20px; margin: 0;'>
                <div style='max-width: 600px; margin: 40px auto; background-color: #ffffff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);'>
                    <h2 style='color: #e11d48; text-align: center;'>🔐  Your Secure Login OTP</h2>
                    <p style='font-size: 16px; color: #333;'>Dear User,</p>
                    <p style='font-size: 15px; color: #555; line-height: 1.6;'>For your security, <strong>never share your OTP</strong> with anyone. Use this code to complete your login on the official platform.</p>
                    <div style='text-align: center; margin: 30px 0;'>
                        <span style='display: inline-block; background-color: #f3f4f6; color: #111827; font-size: 32px; letter-spacing: 5px; padding: 15px 30px; border-radius: 8px; font-weight: bold; border: 1px dashed #e11d48;'>
                            $otp
                        </span>
                    </div>
                    <p style='font-size: 14px; color: #999; text-align: center;'>This OTP expires in 10 minutes. If this wasn't you, please ignore this email.</p>
                </div>
            </body></html>
        ";

        $mail->AltBody = "Your OTP is: $otp";

        $mail->send();
        log_action("PHPMailer: OTP email sent to $email");
        return true;
    } catch (PHPMailer\PHPMailer\Exception $e) {
        log_action("PHPMailer Error: " . $mail->ErrorInfo);
        return false;
    }
}


// Helper: Generate 6-digit OTP
function generateRandomNumbersString()
{
    return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

