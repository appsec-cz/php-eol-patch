--TEST--
GHSA-68fq-fh7h-f8gr (OpenSSL signed phar replaced by a keyless-signed one, all keyless algorithms and formats)
--SKIPIF--
<?php
if (!extension_loaded("phar")) die("skip phar extension required");
if (!extension_loaded("openssl")) die("skip openssl extension required");
$sigs = Phar::getSupportedSignatures();
foreach (["OpenSSL", "MD5", "SHA-1", "SHA-256", "SHA-512"] as $s) {
    if (!in_array($s, $sigs)) die("skip $s support required");
}
?>
--INI--
phar.readonly=0
phar.require_hash=1
--FILE--
<?php
$base = __DIR__ . '/' . basename(__FILE__, '.php');
$private = openssl_pkey_get_private(file_get_contents(__DIR__ . '/files/private.pem'));
$pkey = '';
openssl_pkey_export($private, $pkey, null, ['config' => __DIR__ . '/files/openssl.cnf']);
$pubkey = openssl_pkey_get_details($private)['key'];

function build($base, $suffix, $code, $algo, $pkey = null)
{
    static $n = 0;
    $file = $base . '.build' . ++$n . '.' . $suffix; // fresh name, a reused one would come back from the cache
    $phar = new Phar($file);
    $phar['main.php'] = $code;
    $phar->setStub('<?php __HALT_COMPILER();');
    if ($pkey === null) {
        $phar->setSignatureAlgorithm($algo);
    } else {
        $phar->setSignatureAlgorithm($algo, $pkey);
    }
    unset($phar);
    return file_get_contents($file);
}

// every case is opened under its own name, a phar opened before comes back from the cache
function run($file, $archive, $pubkey)
{
    file_put_contents($file, $archive);
    if ($pubkey !== null) {
        file_put_contents($file . '.pubkey', $pubkey);
    }
    try {
        $phar = new Phar($file);
        echo $phar->getSignature()['hash_type'], ": ";
        include 'phar://' . $file . '/main.php';
    } catch (UnexpectedValueException $e) {
        echo "rejected: ", (strpos($e->getMessage(), 'openssl public key found, but the signature does not use openssl') !== false ? 'downgrade refused' : $e->getMessage()), "\n";
    }
}

$algos = ['md5' => Phar::MD5, 'sha1' => Phar::SHA1, 'sha256' => Phar::SHA256, 'sha512' => Phar::SHA512];
foreach (['phar', 'phar.tar', 'phar.zip'] as $suffix) {
    echo "== $suffix\n";
    $genuine = build($base, $suffix, '<?php echo "GENUINE\n";', Phar::OPENSSL, $pkey);
    run("$base.genuine.$suffix", $genuine, $pubkey);
    foreach ($algos as $name => $algo) {
        $evil = build($base, $suffix, '<?php echo "ATTACKER CODE\n";', $algo);
        // the attacker replaced the archive, the trusted public key is still next to it
        run("$base.evil-$name.$suffix", $evil, $pubkey);
        // without a public key a keyless signature is still accepted
        run("$base.nokey-$name.$suffix", $evil, null);
    }
}
?>
--CLEAN--
<?php
$base = __DIR__ . '/' . basename(__FILE__, '.clean.php');
foreach (glob($base . '.*.phar*') as $file) {
    unlink($file);
}
?>
--EXPECT--
== phar
OpenSSL: GENUINE
rejected: downgrade refused
MD5: ATTACKER CODE
rejected: downgrade refused
SHA-1: ATTACKER CODE
rejected: downgrade refused
SHA-256: ATTACKER CODE
rejected: downgrade refused
SHA-512: ATTACKER CODE
== phar.tar
OpenSSL: GENUINE
rejected: downgrade refused
MD5: ATTACKER CODE
rejected: downgrade refused
SHA-1: ATTACKER CODE
rejected: downgrade refused
SHA-256: ATTACKER CODE
rejected: downgrade refused
SHA-512: ATTACKER CODE
== phar.zip
OpenSSL: GENUINE
rejected: downgrade refused
MD5: ATTACKER CODE
rejected: downgrade refused
SHA-1: ATTACKER CODE
rejected: downgrade refused
SHA-256: ATTACKER CODE
rejected: downgrade refused
SHA-512: ATTACKER CODE
