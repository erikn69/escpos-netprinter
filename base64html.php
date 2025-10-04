<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header("Access-Control-Allow-Headers: X-Requested-With");

function isBase64(string $string): bool
{
    // 1. Check for valid Base64 characters and padding.
    // This regex matches the Base64 alphabet (A-Z, a-z, 0-9, +, /)
    // and allows for optional padding characters (=) at the end.
    // It also accounts for potential whitespace like newlines or carriage returns.
    if (!preg_match('/^[a-zA-Z0-9+\/=\s]*$/', $string)) {
        return false;
    }

    // 2. Decode the string in strict mode.
    // The 'true' argument ensures that base64_decode will return false
    // if the input contains invalid characters or is not properly padded.
    $decoded = base64_decode($string, true);

    // 3. If decoding failed, it's not valid Base64.
    if ($decoded === false) {
        return false;
    }

    // 4. Re-encode the decoded string and compare with the original.
    // If the re-encoded string doesn't match the original, it means
    // the original string was not a valid Base64 representation of the decoded data.
    // This step helps to catch cases where the string contains valid Base64 characters
    // but is not a complete or correctly padded Base64 string.
    if (base64_encode($decoded) !== $string) {
        return false;
    }

    return true;
}


if (!isset($_POST["esc"])) $_POST["esc"] = base64_encode(file_get_contents('receipt-with-logo.bin'));

$width = is_numeric($_POST["width"]??null) ? $_POST["width"].'px' : ($_POST["width"] ?? '80mm');
$argv = [null, null, isBase64($_POST["esc"]) ? base64_decode($_POST["esc"]) : $_POST["esc"]];
$argc = count($argv);

ob_start();
require_once __DIR__ . '/esc2html.php';
$html = ob_get_clean();

echo ($_POST['asRaw'] ?? ($_GET['asRaw'] ?? false)) ? $html : base64_encode($html);
