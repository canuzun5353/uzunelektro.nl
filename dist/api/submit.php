<?php
declare(strict_types=1);
session_start();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Alleen POST toegestaan.'); }
if (!empty($_POST['website'] ?? '')) { header('Location: /bedankt/'); exit; }
if (isset($_SESSION['last_submit']) && time() - (int)$_SESSION['last_submit'] < 20) { http_response_code(429); exit('Wacht even voordat u opnieuw verzendt.'); }
$_SESSION['last_submit'] = time();

$recipient = 'info@uzunelektro.nl';
$allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'];
$fields = [];
foreach ($_POST as $key => $value) {
  if ($key === 'website') continue;
  $label = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$key);
  if (is_array($value)) $value = implode(', ', array_map('strip_tags', $value));
  $fields[] = $label . ': ' . trim(strip_tags((string)$value));
}
$emailValue = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '';
$name = trim(strip_tags((string)($_POST['naam'] ?? (($_POST['voornaam'] ?? '') . ' ' . ($_POST['achternaam'] ?? '')))));
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
header('Location: /bedankt/');
exit;
?>