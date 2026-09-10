<?php
declare(strict_types=1);

/**
 * Generate the VAPID keypair that identifies this server to push services.
 *
 *     php tools/make-vapid.php
 *
 * Run it once and paste the two lines it prints into .env. The pair is this
 * deployment's identity: change it and every existing subscription stops
 * working, because the public key is baked into each one at subscribe time.
 * So generate it once per deployment, keep it out of the repo, and if you run
 * a staging site give that its own pair.
 *
 * VAPID is just "prove you are the same server that owns these subscriptions":
 * an ECDSA P-256 keypair, whose public half the browser stores and whose
 * private half signs a short JWT on every send. See app/Push.php.
 */

require __DIR__ . '/../app/bootstrap.php';

if (!extension_loaded('openssl')) {
    fwrite(STDERR, "The openssl extension is required to generate VAPID keys.\n");
    exit(1);
}

$res = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name'       => 'prime256v1',
]);
if ($res === false) {
    fwrite(STDERR, "openssl_pkey_new failed: " . openssl_error_string() . "\n");
    exit(1);
}

$details = openssl_pkey_get_details($res);
if ($details === false || !isset($details['ec']['d'], $details['ec']['x'], $details['ec']['y'])) {
    fwrite(STDERR, "Could not read the EC key details.\n");
    exit(1);
}

/** base64url, unpadded - what the Push API and JWT both expect. */
function b64u(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** Left-pad to exactly 32 bytes; openssl trims leading zero bytes. */
function pad32(string $bin): string
{
    return str_pad($bin, 32, "\0", STR_PAD_LEFT);
}

// The public key travels as an uncompressed point: 0x04 || X || Y, 65 bytes.
$public  = b64u("\x04" . pad32($details['ec']['x']) . pad32($details['ec']['y']));
$private = b64u(pad32($details['ec']['d']));

echo "Add these to .env (and keep VAPID_PRIVATE_KEY secret):\n\n";
echo "VAPID_PUBLIC_KEY={$public}\n";
echo "VAPID_PRIVATE_KEY={$private}\n";
echo "VAPID_SUBJECT=mailto:you@example.com\n\n";
echo "VAPID_SUBJECT must be a mailto: or https: URL a push service can\n";
echo "contact you at. Some services reject a send without a usable one.\n";
