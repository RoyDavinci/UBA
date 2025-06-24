<?php
// Include PHPMailer classes manually
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';
require 'PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Create instance of PHPMailer
$mail = new PHPMailer(true);

try {
    // Server settings
    $mail->isSMTP();
    $mail->Host       = 'email-smtp.us-east-1.amazonaws.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'AKIAX7YTA767FYW7IXJO';
    $mail->Password   = 'BHcB+rfmYUOHkYwa94z3t/BdUM+7VmVD4ux8Eo14x/js';
    $mail->SMTPSecure = 'tls'; // 'ssl' also works on port 465
    $mail->Port       = 587;

    // Sender and recipient
    $mail->setFrom('mail@redtechlimited.com', 'RedTech Mailer');
    $mail->addAddress('funsho.adeosun@oatek.net', 'Recipient Name'); // change recipient

    // Email content
    $mail->isHTML(true);
    $mail->Subject = 'Test Email via Amazon SES SMTP';
    $mail->Body    = '<strong>Hello!</strong><br>This is a test email using Amazon SES.';
    $mail->AltBody = 'Hello! This is a test email using Amazon SES.';

    $mail->send();
    echo 'Email has been sent';
} catch (Exception $e) {
    echo "Email could not be sent. Error: {$mail->ErrorInfo}";
}
