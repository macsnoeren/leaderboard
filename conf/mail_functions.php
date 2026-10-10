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
 * Gedeelde SMTP-instellingen. SMTP_SECURE ('tls', 'ssl' of '') en SMTP_AUTH zijn optioneel;
 * zonder die constanten blijft het oude gedrag (STARTTLS met inloggen).
 */
function createMailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Timeout = 10;
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->Host = SMTP_HOST;
    $mail->Port = SMTP_PORT;

    $mail->SMTPAuth = defined('SMTP_AUTH') ? (bool)SMTP_AUTH : true;
    if ($mail->SMTPAuth) {
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
    }

    $secure = defined('SMTP_SECURE') ? SMTP_SECURE : 'tls';
    if ($secure === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($secure === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        // Bijvoorbeeld Mailpit: geen versleuteling, ook niet automatisch
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }

    $mail->setFrom(FROM_EMAIL, FROM_NAME);
    $mail->isHTML(true);
    return $mail;
}

/**
 * Verstuurt een e-mail wanneer een team een level omhoog gaat.
 */
function sendLevelUpEmail($db, $to, $team_id, $team_name, $level) {
    // Controleer of e-mail is ingeschakeld
    if (defined('ENABLE_EMAIL') && ENABLE_EMAIL === false) {
        return true;
    }

    try {
        $mail = createMailer();
        $mail->addAddress($to, $team_name);
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
        return isset($mail) ? $mail->ErrorInfo : $e->getMessage();
    }
}

/**
 * Verstuurt een welkomstmail naar een nieuw aangemaakt team.
 */
function sendWelcomeEmail($to, $team_name, $token) {
    // Controleer of e-mail is ingeschakeld
    if (defined('ENABLE_EMAIL') && ENABLE_EMAIL === false) {
        return true;
    }

    try {
        $mail = createMailer();
        $mail->addAddress($to, $team_name);
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
        return isset($mail) ? $mail->ErrorInfo : $e->getMessage();
    }
}

/**
 * Verstuurt een accountmail met één knop. $paragraphs is platte tekst en wordt ge-escaped.
 * Geeft true terug, of de foutmelding van PHPMailer.
 */
function sendAccountEmail($to, $subject, $heading, array $paragraphs, $buttonText = null, $link = null) {
    if (defined('ENABLE_EMAIL') && ENABLE_EMAIL === false) {
        return true;
    }

    try {
        $mail = createMailer();
        $mail->addAddress($to);
        $mail->Subject = $subject;

        $body = '<h2>' . htmlspecialchars($heading) . '</h2>';
        $alt = "$heading\n\n";
        foreach ($paragraphs as $i => $text) {
            $body .= '<p>' . htmlspecialchars($text) . '</p>';
            $alt .= "$text\n\n";
            // De knop staat na de eerste alinea
            if ($i === 0 && $link !== null) {
                $href = htmlspecialchars($link);
                $body .= "<p><a href=\"$href\" style=\"background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;\">" . htmlspecialchars($buttonText) . "</a></p>"
                    . "<p style=\"font-size: 0.85em; color: #666;\">Werkt de knop niet? Kopieer dan deze link naar je browser:<br>$href</p>";
                $alt .= "$link\n\n";
            }
        }

        $mail->Body = "<html><body style='font-family: Arial, sans-serif;'>$body</body></html>";
        $mail->AltBody = trim($alt);

        $mail->send();
        return true;
    } catch (Exception $e) {
        return isset($mail) ? $mail->ErrorInfo : $e->getMessage();
    }
}

/**
 * Uitnodiging voor een nieuwe docent of beheerder (link 72 uur geldig).
 */
function sendInviteEmail($to, $link, $invitedBy) {
    return sendAccountEmail(
        $to,
        'Uitnodiging voor het Zebrawave docentenaccount',
        'Je bent uitgenodigd',
        [
            "$invitedBy heeft je uitgenodigd voor een account op het Zebrawave leaderboard. Klik op de knop om je gebruikersnaam en wachtwoord te kiezen.",
            'Deze link is 72 uur geldig en werkt één keer. Is hij verlopen, vraag de beheerder dan om een nieuwe uitnodiging.',
        ],
        'Account activeren',
        $link
    );
}

/**
 * Link om een nieuw wachtwoord in te stellen (60 minuten geldig).
 */
function sendPasswordResetEmail($to, $link) {
    return sendAccountEmail(
        $to,
        'Wachtwoord opnieuw instellen',
        'Nieuw wachtwoord instellen',
        [
            'Er is gevraagd om het wachtwoord van je Zebrawave docentenaccount opnieuw in te stellen. Klik op de knop om een nieuw wachtwoord te kiezen.',
            'Deze link is 60 minuten geldig en werkt één keer.',
            'Heb je dit niet aangevraagd? Dan kun je deze mail negeren.',
        ],
        'Nieuw wachtwoord kiezen',
        $link
    );
}

/**
 * Bevestigingslink naar het nieuwe adres bij een e-mailwijziging (24 uur geldig).
 */
function sendEmailChangeConfirmation($to, $link) {
    return sendAccountEmail(
        $to,
        'Bevestig je nieuwe e-mailadres',
        'Nieuw e-mailadres bevestigen',
        [
            'Je hebt gevraagd om dit adres te gebruiken voor je Zebrawave docentenaccount. Klik op de knop om dat te bevestigen.',
            'Deze link is 24 uur geldig en werkt één keer. Tot je bevestigt, blijft je oude adres in gebruik.',
            'Heb je dit niet aangevraagd? Dan kun je deze mail negeren.',
        ],
        'E-mailadres bevestigen',
        $link
    );
}

/**
 * Melding naar het oude adres nadat het e-mailadres gewijzigd is.
 */
function sendEmailChangedNotice($to, $newEmail) {
    return sendAccountEmail(
        $to,
        'Je e-mailadres is gewijzigd',
        'E-mailadres gewijzigd',
        [
            "Het e-mailadres van je Zebrawave docentenaccount is gewijzigd naar $newEmail. Mails over je account gaan voortaan naar dat adres.",
            'Heb je dit niet zelf gedaan? Neem dan direct contact op met de beheerder.',
        ]
    );
}
