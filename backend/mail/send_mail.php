<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FICHIER : backend/mail/send_mail.php
|--------------------------------------------------------------------------
| Rôle :
| - envoyer des e-mails via Gmail SMTP avec PHPMailer
| - écrire les erreurs dans un fichier de log
|--------------------------------------------------------------------------
*/

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../libs/PHPMailer/Exception.php';
require_once __DIR__ . '/../../libs/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../../libs/PHPMailer/SMTP.php';

// La configuration SMTP (hôte, port, identifiants) est définie dans config.php.
require_once __DIR__ . '/../../config.php';

/*
|--------------------------------------------------------------------------
| Fichier de log
|--------------------------------------------------------------------------
*/
const MAIL_LOG_FILE = __DIR__ . '/mail_debug.log';

/**
 * Écrit une ligne dans le fichier de log.
 */
function mailLog(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents(MAIL_LOG_FILE, $line, FILE_APPEND);
}

/**
 * Sécurise l'affichage HTML.
 */
function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Formate la date de l'événement.
 */
function formatEventDateForMail(?string $start, ?string $end, bool $allDay): string
{
    try {
        if ($start === null || trim($start) === '') {
            return 'Date non définie';
        }

        if ($allDay) {
            $startDt = new DateTimeImmutable($start);
            $startText = $startDt->format('d/m/Y');

            if ($end !== null && trim($end) !== '') {
                $endDt = new DateTimeImmutable($end);
                $endText = $endDt->format('d/m/Y');

                if ($startText !== $endText) {
                    return "Du {$startText} au {$endText} (toute la journée)";
                }
            }

            return "{$startText} (toute la journée)";
        }

        $startDt = new DateTimeImmutable($start);
        $startText = $startDt->format('d/m/Y à H:i');

        if ($end !== null && trim($end) !== '') {
            $endDt = new DateTimeImmutable($end);
            $endText = $endDt->format('d/m/Y à H:i');
            return "Du {$startText} au {$endText}";
        }

        return $startText;
    } catch (Throwable $e) {
        return 'Date non définie';
    }
}

/**
 * Construit le HTML du mail.
 *
 * @param array  $eventData     Données de l'événement (title, description, etc.)
 * @param string $recipientName Nom de l'invité (optionnel)
 * @param string $acceptUrl     URL du lien "Accepter" (optionnel — absent = pas de boutons)
 * @param string $declineUrl    URL du lien "Refuser"  (optionnel — absent = pas de boutons)
 */
function buildInvitationHtml(
    array  $eventData,
    string $recipientName = '',
    string $acceptUrl     = '',
    string $declineUrl    = ''
): string {
    $title = h($eventData['title'] ?? 'Évènement');
    $description = nl2br(h($eventData['description'] ?? ''));
    $location = h($eventData['location'] ?? '');
    $status = h($eventData['status_label'] ?? 'Confirmé');
    $priority = h($eventData['priority_label'] ?? 'Normale');
    $dateText = h($eventData['date_text'] ?? 'Date non définie');
    $recipient = h($recipientName !== '' ? $recipientName : 'invité');

    $locationBlock = $location !== ''
        ? "<tr><td style=\"padding:8px 0;font-weight:bold;width:140px;\">Lieu</td><td style=\"padding:8px 0;\">{$location}</td></tr>"
        : '';

    $descriptionBlock = $description !== ''
        ? "
            <div style=\"margin-top:20px;\">
                <div style=\"font-size:16px;font-weight:bold;margin-bottom:8px;\">Description</div>
                <div style=\"line-height:1.6;color:#334155;\">{$description}</div>
            </div>
          "
        : '';

    /*
    | Bloc des boutons Accepter / Refuser
    | ------------------------------------
    | Affiché uniquement si les deux URLs sont fournies.
    | Les liens pointent vers respond_invitation.php avec le token
    | de l'invité et la réponse souhaitée.
    |
    | On utilise des <a> stylisés comme boutons car les clients e-mail
    | (Gmail, Outlook...) n'affichent pas toujours les <button> HTML.
    | Les <a> sont universellement supportés dans les e-mails.
    */
    $responseButtonsBlock = ($acceptUrl !== '' && $declineUrl !== '')
        ? "
            <div style=\"margin: 28px 0; text-align: center;\">
                <div style=\"font-size:15px;font-weight:bold;color:#0f172a;margin-bottom:16px;\">
                    Votre réponse :
                </div>
                <a href=\"{$acceptUrl}\"
                   style=\"display:inline-block;padding:12px 28px;background:#1f9d63;
                           color:#ffffff;border-radius:10px;font-size:15px;font-weight:700;
                           text-decoration:none;margin:0 8px;\">
                    ✓ Accepter
                </a>
                <a href=\"{$declineUrl}\"
                   style=\"display:inline-block;padding:12px 28px;background:#d94b4b;
                           color:#ffffff;border-radius:10px;font-size:15px;font-weight:700;
                           text-decoration:none;margin:0 8px;\">
                    ✗ Refuser
                </a>
            </div>
          "
        : '';

    return "
    <!DOCTYPE html>
    <html lang=\"fr\">
    <head>
        <meta charset=\"UTF-8\">
        <title>Invitation événement</title>
    </head>
    <body style=\"margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;\">
        <div style=\"max-width:680px;margin:30px auto;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 12px 35px rgba(0,0,0,0.08);\">
            <div style=\"background:linear-gradient(135deg,#1e293b,#0f172a);padding:28px 32px;color:#ffffff;\">
                <div style=\"font-size:13px;letter-spacing:1px;text-transform:uppercase;opacity:0.85;\">Calendars App</div>
                <div style=\"font-size:28px;font-weight:800;margin-top:8px;\">Invitation à un événement</div>
            </div>

            <div style=\"padding:30px 32px;\">
                <p style=\"margin-top:0;color:#0f172a;font-size:16px;\">
                    Bonjour <strong>{$recipient}</strong>,
                </p>

                <p style=\"color:#334155;font-size:15px;line-height:1.6;\">
                    Vous avez été ajouté à l'événement suivant :
                </p>

                <div style=\"margin:20px 0;padding:18px 20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;\">
                    <div style=\"font-size:22px;font-weight:800;color:#0f172a;margin-bottom:10px;\">{$title}</div>

                    <table style=\"width:100%;border-collapse:collapse;font-size:14px;color:#334155;\">
                        <tr>
                            <td style=\"padding:8px 0;font-weight:bold;width:140px;\">Date</td>
                            <td style=\"padding:8px 0;\">{$dateText}</td>
                        </tr>
                        {$locationBlock}
                        <tr>
                            <td style=\"padding:8px 0;font-weight:bold;\">Statut</td>
                            <td style=\"padding:8px 0;\">{$status}</td>
                        </tr>
                        <tr>
                            <td style=\"padding:8px 0;font-weight:bold;\">Priorité</td>
                            <td style=\"padding:8px 0;\">{$priority}</td>
                        </tr>
                    </table>

                    {$descriptionBlock}
                </div>

                {$responseButtonsBlock}

                <p style=\"color:#64748b;font-size:13px;line-height:1.6;margin-top:24px;\">
                    Cet e-mail a été envoyé automatiquement par l'application Calendars.
                </p>
            </div>
        </div>
    </body>
    </html>
    ";
}

/**
 * Envoie un seul e-mail.
 */
function sendSingleMail(string $toEmail, string $toName, string $subject, string $htmlBody): array
{
    $mail = new PHPMailer(true);

    try {
        mailLog("Début envoi vers {$toEmail}");

        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_APP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        /*
        |--------------------------------------------------------------------------
        | IMPORTANT
        |--------------------------------------------------------------------------
        | On laisse à 0 pour ne pas casser les réponses JSON.
        |--------------------------------------------------------------------------
        */
        $mail->SMTPDebug = 0;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];

        $mail->setFrom(SMTP_USERNAME, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = trim(strip_tags(str_replace(
            ['<br>', '<br/>', '<br />'],
            "\n",
            $htmlBody
        )));

        $mail->send();

        mailLog("Succès envoi vers {$toEmail}");

        return [
            'success' => true,
            'email' => $toEmail,
            'error' => null,
        ];
    } catch (Throwable $e) {
        mailLog("Erreur envoi vers {$toEmail} : " . $e->getMessage());

        return [
            'success' => false,
            'email' => $toEmail,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * Envoie plusieurs invitations.
 */
function sendEventInvitations(array $eventData, array $recipients): array
{
    $results = [];
    $subject = 'Invitation : ' . (string)($eventData['title'] ?? 'Évènement');

    foreach ($recipients as $recipient) {
        $email = trim((string)($recipient['email'] ?? ''));
        $name = trim((string)($recipient['name'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $results[] = [
                'success' => false,
                'email' => $email,
                'error' => 'Adresse e-mail invalide',
            ];
            continue;
        }

        /*
        | Les URLs accept/decline sont générées dans save_event.php à partir
        | du token de l'invité et de APP_URL. Elles sont passées ici dans
        | le tableau $recipient pour être transmises au template HTML.
        | Si absentes (anciens appels sans token), les boutons ne s'affichent pas.
        */
        $acceptUrl  = (string)($recipient['accept_url']  ?? '');
        $declineUrl = (string)($recipient['decline_url'] ?? '');

        $htmlBody = buildInvitationHtml($eventData, $name, $acceptUrl, $declineUrl);
        $results[] = sendSingleMail($email, $name, $subject, $htmlBody);
    }

    return $results;
}