<?php
declare(strict_types=1);
session_start();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

function spamExit(): void {
  header('Location: /bedankt/', true, 303);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Alleen POST toegestaan.'); }

$allowedHosts = ['uzunelektro.nl', 'www.uzunelektro.nl'];
$source = (string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
$sourceHost = strtolower((string)parse_url($source, PHP_URL_HOST));
if ($sourceHost === '' || !in_array($sourceHost, $allowedHosts, true)) spamExit();

if (!empty($_POST['website'] ?? '') || !empty($_POST['company_url'] ?? '')) spamExit();
$started = filter_var($_POST['form_started'] ?? null, FILTER_VALIDATE_INT);
$elapsed = $started ? time() - (int)$started : 0;
if (!$started || $elapsed < 3 || $elapsed > 43200) spamExit();

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateFile = sys_get_temp_dir() . '/uzun-form-' . hash('sha256', $ip) . '.json';
$now = time();
$handle = @fopen($rateFile, 'c+');
if ($handle) {
  if (flock($handle, LOCK_EX)) {
    $raw = stream_get_contents($handle);
    $attempts = json_decode($raw ?: '[]', true);
    if (!is_array($attempts)) $attempts = [];
    $attempts = array_values(array_filter($attempts, fn($stamp) => is_int($stamp) && $stamp > $now - 3600));
    if (count($attempts) >= 6) { flock($handle, LOCK_UN); fclose($handle); spamExit(); }
    $attempts[] = $now;
    ftruncate($handle, 0); rewind($handle); fwrite($handle, json_encode($attempts)); fflush($handle);
    flock($handle, LOCK_UN);
  }
  fclose($handle);
}

$flatten = static function ($value): string {
  if (is_array($value)) return implode(' ', array_map(static fn($item) => strip_tags((string)$item), $value));
  return strip_tags((string)$value);
};
$allText = implode("\n", array_map($flatten, $_POST));
if (strlen($allText) > 12000) spamExit();
if (preg_match('/[\p{Cyrillic}]/u', $allText)) spamExit();
if (preg_match('/(?:https?:\/\/|www\.|tinyurl\.com|bit\.ly|t\.me\/)/iu', $allText)) spamExit();

$emailValue = trim((string)($_POST['email'] ?? ''));
if ($emailValue !== '' && !filter_var($emailValue, FILTER_VALIDATE_EMAIL)) spamExit();
$name = trim(strip_tags((string)($_POST['naam'] ?? (($_POST['voornaam'] ?? '') . ' ' . ($_POST['achternaam'] ?? '')))));
if (strlen($name) < 2 || strlen($name) > 120 || preg_match('/\d{4,}/', $name)) spamExit();
$phoneRaw = trim((string)($_POST['telefoon'] ?? ''));
$phone = preg_replace('/[\s().-]+/', '', $phoneRaw);
if (!preg_match('/^(?:\+31|0031|0)[1-9]\d{8,9}$/', $phone)) spamExit();
$postcode = strtoupper(preg_replace('/\s+/', '', trim((string)($_POST['postcode'] ?? ''))));
if ($postcode !== '' && !preg_match('/^[1-9]\d{3}[A-Z]{2}$/', $postcode)) spamExit();
$place = trim((string)($_POST['plaats'] ?? ''));
if ($place !== '' && (strlen($place) > 100 || str_contains($place, '@') || preg_match('/\d{3,}/', $place))) spamExit();

if (isset($_SESSION['last_submit']) && $now - (int)$_SESSION['last_submit'] < 30) spamExit();
$_SESSION['last_submit'] = $now;

$recipient = 'info@uzunelektro.nl';
$allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'];
$fields = [];
foreach ($_POST as $key => $value) {
  if (in_array($key, ['website', 'company_url', 'form_started'], true)) continue;
  $label = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$key);
  if (is_array($value)) $value = implode(', ', array_map('strip_tags', $value));
  $fields[] = $label . ': ' . trim(strip_tags((string)$value));
}
$formType = preg_replace('/[^a-z0-9 -]/i', '', trim((string)($_POST['formulier'] ?? 'website')));
$subject = 'Nieuwe ' . $formType . ' - Uzun Elektro website';
$boundary = 'uzun-' . bin2hex(random_bytes(12));
$body = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . implode("\r\n", $fields) . "\r\n";
$files = $_FILES['bijlagen'] ?? null;
if ($files && is_array($files['name'])) {
  $count = min(count($files['name']), 5); $total = 0; $finfo = new finfo(FILEINFO_MIME_TYPE);
  for ($i=0; $i<$count; $i++) {
    if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
    if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) { http_response_code(400); exit('Een bestand kon niet worden ontvangen.'); }
    $size = (int)$files['size'][$i]; $total += $size;
    if ($size > 8 * 1024 * 1024 || $total > 20 * 1024 * 1024) { http_response_code(413); exit('De bestanden zijn te groot.'); }
    $mime = $finfo->file($files['tmp_name'][$i]);
    if (!isset($allowed[$mime])) { http_response_code(415); exit('Dit bestandstype is niet toegestaan.'); }
    $filename = 'bijlage-' . ($i + 1) . '.' . $allowed[$mime];
    $data = chunk_split(base64_encode((string)file_get_contents($files['tmp_name'][$i])));
    $body .= '--' . $boundary . "\r\nContent-Type: " . $mime . "; name=\"" . $filename . "\"\r\nContent-Disposition: attachment; filename=\"" . $filename . "\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $data . "\r\n";
  }
}
$body .= '--' . $boundary . "--\r\n";
$headers = "From: Uzun Elektro website <noreply@uzunelektro.nl>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"" . $boundary . "\"";
if ($emailValue) $headers .= "\r\nReply-To: " . $emailValue;
$sent = mail($recipient, $subject, $body, $headers);
if (!$sent) { http_response_code(500); exit('Verzenden is niet gelukt. Bel of WhatsApp ons via 06 13 27 12 61.'); }
if ($emailValue) {
  $confirmSubject = 'Ontvangstbevestiging Uzun Elektro';
  $confirmBody = "Beste " . ($name ?: 'klant') . ",\n\nBedankt voor uw aanvraag. Wij hebben uw gegevens ontvangen en reageren binnen één werkdag.\n\nMet vriendelijke groet,\nUzun Elektro\n06 13 27 12 61\ninfo@uzunelektro.nl";
  $confirmHeaders = "From: Uzun Elektro <info@uzunelektro.nl>\r\nContent-Type: text/plain; charset=UTF-8";
  mail($emailValue, $confirmSubject, $confirmBody, $confirmHeaders);
}
header('Location: /bedankt/', true, 303);
exit;
?>
