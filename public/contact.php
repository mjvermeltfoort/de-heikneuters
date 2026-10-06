<?php
declare(strict_types=1);

function redirect_to_contact(string $status): never
{
    header('Location: /contact/?status=' . rawurlencode($status) . '#contactformulier', true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect_to_contact('error');
}

if (trim((string)($_POST['website'] ?? '')) !== '') {
    redirect_to_contact('success');
}

$started = (int)($_POST['form_started'] ?? 0);
if ($started > 0 && (time() - $started) < 2) {
    redirect_to_contact('error');
}

$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$subject = trim((string)($_POST['subject'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

$name = str_replace(["\r", "\n"], ' ', $name);
$subject = str_replace(["\r", "\n"], ' ', $subject);

if (
    $name === '' ||
    mb_strlen($name) > 120 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    mb_strlen($email) > 180 ||
    $subject === '' ||
    mb_strlen($subject) > 160 ||
    $message === '' ||
    mb_strlen($message) > 5000
) {
    redirect_to_contact('error');
}

$recipient = 'info@de-heikneuters.nl';
$mailSubject = 'Website: ' . $subject;
$encodedSubject = '=?UTF-8?B?' . base64_encode($mailSubject) . '?=';

$body = implode("\r\n", [
    'Nieuw bericht via de-heikneuters.nl',
    '',
    'Naam: ' . $name,
    'E-mail: ' . $email,
    'Onderwerp: ' . $subject,
    '',
    'Bericht:',
    $message,
]);

$headers = implode("\r\n", [
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'From: Website C.V. De Heikneuters <info@de-heikneuters.nl>',
    'Reply-To: ' . $email,
    'X-Mailer: PHP/' . PHP_VERSION,
]);

$sent = @mail($recipient, $encodedSubject, $body, $headers);

redirect_to_contact($sent ? 'success' : 'error');
