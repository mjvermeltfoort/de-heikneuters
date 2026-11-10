<?php
/**
 * Server-side spamfilter voor de contact- en vriendenformulieren.
 * Geplaatst in public/ zodat Astro het PHP-bestand onveranderd naar dist/ kopieert.
 * Geen persoonsgegevens opslaan: alleen korte sessietokens en inzendtijdstippen.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function reject(int $status, string $message): void
{
    response($status, ['success' => false, 'message' => $message]);
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    reject(405, 'Deze aanvraag wordt niet ondersteund.');
}

// Weiger browseraanvragen van een andere website. Dit vervangt geen tokencontrole.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
if ($origin !== '' && (!is_string($host) || parse_url($origin, PHP_URL_HOST) !== $host)) {
    reject(403, 'Het formulier kon niet worden verwerkt.');
}
if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
    reject(403, 'Het formulier kon niet worden verwerkt.');
}

ini_set('session.use_strict_mode', '1');
session_name('hn_form');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (!session_start()) {
    reject(503, 'Het formulier is tijdelijk niet beschikbaar. Probeer het later opnieuw.');
}

$now = microtime(true);
$tokens = $_SESSION['form_tokens'] ?? [];
if (!is_array($tokens)) {
    $tokens = [];
}
$tokens = array_filter($tokens, static fn ($created) => is_numeric($created) && $now - (float) $created < 30 * 60);

if ($method === 'GET') {
    if (($_GET['token'] ?? '') !== '1') {
        reject(404, 'Niet gevonden.');
    }

    try {
        $token = bin2hex(random_bytes(24));
    } catch (Throwable $exception) {
        reject(503, 'Het formulier is tijdelijk niet beschikbaar.');
    }
    $tokens[hash('sha256', $token)] = $now;
    $_SESSION['form_tokens'] = array_slice($tokens, -5, null, true);
    response(200, ['success' => true, 'token' => $token]);
}

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16000) {
    reject(413, 'Het bericht is te lang.');
}

$token = $_POST['form_token'] ?? null;
$tokenKey = is_string($token) && preg_match('/^[a-f0-9]{48}$/', $token)
    ? hash('sha256', $token)
    : null;
$issuedAt = $tokenKey !== null ? ($tokens[$tokenKey] ?? null) : null;
if ($tokenKey !== null) {
    unset($tokens[$tokenKey]);
}
$_SESSION['form_tokens'] = $tokens;
session_write_close();

if (!is_numeric($issuedAt) || $now - (float) $issuedAt >= 1800) {
    reject(403, 'De formuliercontrole is verlopen. Probeer opnieuw te verzenden.');
}
if ($now - (float) $issuedAt < 3) {
    reject(429, 'Het formulier is erg snel verstuurd. Probeer het over enkele seconden opnieuw.');
}

// Honeypot: normale bezoekers zien en vullen dit veld niet in.
if (!is_string($_POST['website_url'] ?? null) || trim($_POST['website_url']) !== '') {
    reject(400, 'Het formulier kon niet worden verzonden. Controleer je invoer.');
}

function field(string $name, int $maximum, bool $required = true): ?string
{
    $value = $_POST[$name] ?? null;
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if ($value === '' && !$required) {
        return '';
    }
    // Begrens op Unicode-tekens, niet alleen bytes.
    if ($value === '' || preg_match('/^.{1,' . $maximum . '}$/us', $value) !== 1) {
        return null;
    }
    return $value;
}

function isGeneratedText(string $value): bool
{
    return preg_match('/^[a-z]{18,}$/i', $value) === 1
        && preg_match_all('/[A-Z]/', $value) >= 4
        && preg_match_all('/[a-z]/', $value) >= 4;
}

function suspiciousContent(string $name, string $message, string $other = ''): bool
{
    $combined = $name . "\n" . $other . "\n" . $message;
    preg_match_all('~(?:https?://|www\.)\S+~iu', $combined, $matches);
    $linkCount = count($matches[0]);

    if (preg_match('~(?:https?://|www\.)~i', $name . ' ' . $other) === 1) {
        return true;
    }
    if (preg_match('/<\s*\/?\s*[a-z][^>]*>/i', $combined) === 1 || $linkCount > 2) {
        return true;
    }
    if ($linkCount > 0 && preg_match('/\b(?:claim your|promo(?:code)?|grab now|scam service|casino bonus)\b/i', $combined) === 1) {
        return true;
    }
    return isGeneratedText($name) && isGeneratedText($message);
}

$kind = $_POST['form_type'] ?? null;
$name = field('entry.88467631', 120);
$emailField = $kind === 'contact' ? 'entry.1710876788' : 'emailAddress';
$email = field($emailField, 180);
if (!is_string($kind) || !in_array($kind, ['contact', 'vrienden'], true)
    || $name === null || $email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    reject(400, 'Controleer je naam en e-mailadres.');
}

$payload = [
    'entry.88467631' => $name,
    $emailField => $email,
    'fvv' => '1',
    'pageHistory' => '0',
    'submissionTimestamp' => '-1',
];

if ($kind === 'contact') {
    $category = field('entry.1631100819', 160);
    $question = field('entry.2137376407', 5000);
    $other = field('entry.1631100819.other_option_response', 160, false);
    $categories = [
        'Algemeen', 'De Kneuterkapel', 'Lidmaatschap',
        'Vrienden van C.V. de Heikneuters', 'Carnaval proeven', '__other_option__',
    ];
    if ($category === null || !in_array($category, $categories, true) || $question === null
        || $other === null || ($category === '__other_option__' && $other === '')) {
        reject(400, 'Controleer het onderwerp en je vraag.');
    }
    if (suspiciousContent($name, $question, $other)) {
        reject(400, 'Het bericht bevat ongebruikelijke inhoud of links. Pas het aan en probeer opnieuw.');
    }
    $payload['entry.1631100819'] = $category;
    $payload['entry.2137376407'] = $question;
    if ($category === '__other_option__') {
        $payload['entry.1631100819.other_option_response'] = $other;
    }
    $googleUrl = 'https://docs.google.com/forms/d/e/1FAIpQLSdQKqZbDT8ACClFDrf-zxwpt2mzrPb0CvkYVLAxvkibWUgn1A/formResponse';
} else {
    $amount = field('entry.1631100819', 12);
    $notes = field('entry.2137376407', 3000, false);
    $amounts = ['€ 11,11', '€ 22,22', '€ 33,33', '€ 44,44', '€ 55,55', '€ 66,66', '€ 77,77', '€ 88,88'];
    if ($amount === null || !in_array($amount, $amounts, true) || $notes === null) {
        reject(400, 'Controleer de gekozen bijdrage en eventuele opmerkingen.');
    }
    if (suspiciousContent($name, $notes)) {
        reject(400, 'De aanmelding bevat ongebruikelijke inhoud of links. Pas deze aan en probeer opnieuw.');
    }
    $payload['entry.1631100819'] = $amount;
    $payload['entry.2137376407'] = $notes;
    $googleUrl = 'https://docs.google.com/forms/d/e/1FAIpQLSeu1iuHXbT969Nlc_ps89rSt-Oyu6kRawdWi3rthSfAMGbXJw/formResponse';
}

// Limiet per IP-adres en formuliertype. Alleen tijdstippen worden bewaard,
// in een map buiten de publieke webroot (systeem-temporary directory).
$storage = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/heikneuters-form-rate-v1';
if (!is_dir($storage) && !@mkdir($storage, 0700, true) && !is_dir($storage)) {
    reject(503, 'Het formulier is tijdelijk niet beschikbaar.');
}
$ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . $kind);
$handle = @fopen($storage . '/' . $ipHash . '.json', 'c+');
if ($handle === false || !flock($handle, LOCK_EX)) {
    reject(503, 'Het formulier is tijdelijk niet beschikbaar.');
}
$history = json_decode(stream_get_contents($handle) ?: '[]', true);
$history = is_array($history) ? $history : [];
$history = array_values(array_filter($history, static fn ($time) => is_numeric($time) && $now - (float) $time < 900));
if (count($history) >= 3 || (count($history) > 0 && $now - (float) end($history) < 45)) {
    flock($handle, LOCK_UN);
    fclose($handle);
    reject(429, 'Er zijn recent berichten verstuurd. Probeer het later opnieuw.');
}
$history[] = $now;
$json = json_encode($history);
rewind($handle);
ftruncate($handle, 0);
$written = fwrite($handle, $json === false ? '' : $json);
fflush($handle);
flock($handle, LOCK_UN);
fclose($handle);
if ($json === false || $written !== strlen($json)) {
    reject(503, 'Het formulier is tijdelijk niet beschikbaar.');
}

// Stuur uitsluitend gecontroleerde velden naar Google Forms; nooit blind $_POST.
if (!function_exists('curl_init')) {
    reject(503, 'Het formulier is tijdelijk niet beschikbaar.');
}
$curl = curl_init($googleUrl);
if ($curl === false) {
    reject(503, 'Het formulier is tijdelijk niet beschikbaar.');
}
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($payload, '', '&', PHP_QUERY_RFC1738),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);
$result = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($result === false || $status < 200 || $status >= 400) {
    reject(502, 'Verzenden is niet gelukt. Probeer het later opnieuw of neem contact op via e-mail.');
}
response(200, ['success' => true]);
