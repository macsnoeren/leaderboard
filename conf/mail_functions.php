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
 * HTML van een accountmail: tabellen en inline stijl, want mailclients negeren
 * de meeste CSS. Kopbalk in merkkleur, witte inhoud, knop na de eerste alinea met de link ook als
 * tekst, en een grijze voetregel. Alle tekst wordt ge-escaped (teamnamen komen van teams zelf).
 * Mailclients met een donkere modus krijgen via de <style> in de <head> donkere kleuren.
 */
function accountEmailHtml(string $heading, array $paragraphs, ?string $buttonText = null, ?string $link = null): string {
    $brand = '#5B4BDB';
    $font = "font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;";
    $rows = '';
    foreach ($paragraphs as $i => $text) {
        $rows .= '<p style="margin: 0 0 16px; font-size: 15px; line-height: 1.6; color: #141A2B;" class="zw-text">' . htmlspecialchars($text) . '</p>';
        // De knop staat na de eerste alinea
        if ($i === 0 && $link !== null) {
            $href = htmlspecialchars($link);
            $rows .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 8px 0 12px;"><tr>'
                . '<td style="border-radius: 8px; background: ' . $brand . ';" bgcolor="' . $brand . '">'
                . '<a href="' . $href . '" style="display: inline-block; padding: 12px 24px; ' . $font . ' font-size: 15px; font-weight: 600; color: #FFFFFF; text-decoration: none; border-radius: 8px;">' . htmlspecialchars((string)$buttonText) . '</a>'
                . '</td></tr></table>'
                . '<p style="margin: 0 0 20px; font-size: 13px; line-height: 1.5; color: #5F6B85;" class="zw-muted">Werkt de knop niet? Kopieer dan deze link naar je browser:<br>'
                . '<a href="' . $href . '" style="color: ' . $brand . '; word-break: break-all;" class="zw-link">' . $href . '</a></p>';
        }
    }
    return '<!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
        . '<title>' . htmlspecialchars($heading) . '</title>'
        . '<style>@media (prefers-color-scheme: dark) { .zw-bg { background: #0B1020 !important; } .zw-card { background: #121A2E !important; border-color: #26314F !important; }'
        . ' .zw-text, .zw-heading { color: #E6EAF2 !important; } .zw-muted, .zw-foot { color: #9AA4BF !important; } .zw-link { color: #A396FF !important; } }</style>'
        . '</head><body style="margin: 0; padding: 0; background: #F4F6FB;" class="zw-bg">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background: #F4F6FB;" bgcolor="#F4F6FB" class="zw-bg"><tr><td align="center" style="padding: 32px 16px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 560px; ' . $font . '">'
        // Kopbalk
        . '<tr><td style="background: #0B1020; border-radius: 12px 12px 0 0; padding: 18px 28px;" bgcolor="#0B1020">'
        . '<span style="' . $font . ' font-size: 18px; font-weight: 800; letter-spacing: .03em; color: #FFFFFF;">Zebra<span style="color: #8B7CFF;">wave</span></span>'
        . '</td></tr>'
        . '<tr><td style="height: 4px; background: ' . $brand . '; font-size: 0; line-height: 0;" bgcolor="' . $brand . '">&nbsp;</td></tr>'
        // Inhoud
        . '<tr><td style="background: #FFFFFF; border: 1px solid #E3E7F0; border-top: none; border-radius: 0 0 12px 12px; padding: 28px;" bgcolor="#FFFFFF" class="zw-card">'
        . '<h1 style="margin: 0 0 16px; ' . $font . ' font-size: 22px; line-height: 1.3; color: #141A2B;" class="zw-heading">' . htmlspecialchars($heading) . '</h1>'
        . $rows
        . '</td></tr>'
        // Voetregel
        . '<tr><td style="padding: 16px 28px; ' . $font . ' font-size: 12px; line-height: 1.5; color: #8C96AD; text-align: center;" class="zw-foot">'
        . 'Deze mail is automatisch verstuurd door het OperationRoom leaderboard. Je kunt er niet op antwoorden.'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
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

        $alt = "$heading\n\n";
        foreach ($paragraphs as $i => $text) {
            $alt .= "$text\n\n";
            if ($i === 0 && $link !== null) {
                $alt .= "$link\n\n";
            }
        }

        $mail->Body = accountEmailHtml($heading, $paragraphs, $buttonText, $link);
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
        'Uitnodiging voor OperationRoom',
        'Je bent uitgenodigd',
        [
            "$invitedBy heeft je uitgenodigd voor een account op het OperationRoom leaderboard. Klik op de knop om je gebruikersnaam en wachtwoord te kiezen.",
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
            'Er is gevraagd om het wachtwoord van je OperationRoom-account opnieuw in te stellen. Klik op de knop om een nieuw wachtwoord te kiezen.',
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
            'Je hebt gevraagd om dit adres te gebruiken voor je OperationRoom-account. Klik op de knop om dat te bevestigen.',
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
            "Het e-mailadres van je OperationRoom-account is gewijzigd naar $newEmail. Mails over je account gaan voortaan naar dat adres.",
            'Heb je dit niet zelf gedaan? Neem dan direct contact op met de beheerder.',
        ]
    );
}

/**
 * Link naar het teamdashboard, voor een team dat er niet (meer) bij kan of zich net heeft aangemeld.
 * De teamnaam wordt in sendAccountEmail ge-escaped; teams vullen hem bij zelf aanmelden zelf in.
 */
function sendTeamLinkEmail($to, $teamName, $gameName, $link) {
    return sendAccountEmail(
        $to,
        "$teamName: jullie teamdashboard",
        "Welkom, team $teamName!",
        [
            "Jullie doen mee aan $gameName. Via de knop komen jullie op het teamdashboard met de opdrachten en de chat met de operators.",
            'Bewaar deze mail: met deze link komen jullie altijd weer terug op het dashboard. Deel hem alleen binnen je team.',
            'Heel veel succes!',
        ],
        'Naar het teamdashboard',
        $link
    );
}
