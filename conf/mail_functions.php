<?php
/**
 * E-mail functie definitiebestand
 */

require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Verstuurt een e-mail wanneer een team een level omhoog gaat.
 */
function sendLevelUpEmail($db, $to, $team_id, $team_name, $level) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Timeout = 10;
        $mail->SMTPConnectTimeout = 5;
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;

        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addAddress($to, $team_name);

        $mail->isHTML(true);
        $mail->Subject = "$team_name: Gefeliciteerd! Level $level is behaald!";

        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', time() + 86400);

        $stmt = $db->prepare("INSERT INTO download_tokens (team_id, level, token, expires_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([$team_id, $level, $token, $expires_at]);

        $download_link = BASE_URL . "/download.php?token=$token";
        
        $mail->Body = "
          <html>
          <body style='font-family: Arial, sans-serif;'>
          <h2>Gefeliciteerd $team_name!</h2>
             <p>Jullie hebben level $level behaald!</p>
             <p>Klik op de onderstaande link om de documenten te krijgen voor de volgende opdracht:</p>
             <p><a href='$download_link' style='background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Download Artifacts</a></p>
             <p>Ga vooral zo door! Goed bezig!!</p>
             </body>
            </html>
        ";

        $mail->AltBody = "Gefeliciteerd $team_name! Je hebt level $level behaald. Download de volgende opdracht: $download_link";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return $mail->ErrorInfo;
    }
}

/**
 * Verstuurt een welkomstmail naar een nieuw aangemaakt team.
 */
function sendWelcomeEmail($to, $team_name, $token) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Timeout = 10;
        $mail->SMTPConnectTimeout = 5;
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;

        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addAddress($to, $team_name);

        $mail->isHTML(true);
        $mail->Subject = "$team_name: Je bent aangemeld!";

        $leaderboard_link = BASE_URL;
        $dashboard_link = BASE_URL . "/team.php?token=$token";

        $mail->Body = "
          <html><body style='font-family: Arial, sans-serif;'><h2>Welkom $team_name!</h2><p>Je bent aangemeld als recherche team!</p><p>Jullie hebben nu toegang tot jullie eigen dashboard waar opdrachten gedownload kunnen worden zodra jullie het juiste level bereiken.</p><p><strong>Jullie persoonlijke dashboard:</strong> <a href='$dashboard_link' style='color: #667eea;'>$dashboard_link</a></p><p>Houd de stand in de gaten op het: <a href='$leaderboard_link' style='background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Leaderboard</a></p><p>Heel veel succes!!</p></body></html>
        ";

        $mail->AltBody = "Welkom $team_name! Je bent aangemeld. Jouw dashboard: $dashboard_link";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return $mail->ErrorInfo;
    }
}