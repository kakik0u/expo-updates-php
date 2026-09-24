<?php
declare(strict_types=1);
require __DIR__ . '/../server/src/App.php';
$c = new ReflectionClass(OtaApp::class);
$app = $c->newInstanceWithoutConstructor();
$hashHex = $c->getMethod('hashHex');
$b64Hash = $c->getMethod('b64Hash');
$hex = hash('sha256', 'asset');
$b64 = rtrim(strtr(base64_encode(hex2bin($hex)), '+/', '-_'), '=');
assert($hashHex->invoke(null, $b64) === $hex);
assert($b64Hash->invoke(null, $hex) === $b64);
try { $hashHex->invoke(null, '../invalid'); throw new RuntimeException('accepted bad hash'); } catch (ReflectionException|HttpError $e) { /* expected */ }
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => '/etc/ssl/openssl.cnf']);
openssl_pkey_export($key, $private, null, ['config' => '/etc/ssl/openssl.cnf']);
$public = openssl_pkey_get_details($key)['key'];
$certFile = tempnam(sys_get_temp_dir(), 'ota-cert-');
file_put_contents($certFile, $public);
$config = $c->getProperty('config');
$config->setValue($app, ['signing_key_id' => 'main', 'signing_certificate_path' => $certFile]);
$bytes = '{"id":"signed-bytes"}';
openssl_sign($bytes, $sig, $private, OPENSSL_ALGO_SHA256);
$signature = 'sig="' . base64_encode($sig) . '", keyid="main", alg="rsa-v1_5-sha256"';
$signed = $c->getMethod('signed');
assert($signed->invoke($app, $bytes, $signature) === true);
assert($signed->invoke($app, $bytes . ' ', $signature) === false);
assert($signed->invoke($app, $bytes, str_replace('main', 'other', $signature)) === false);
unlink($certFile);
echo "PHP protocol primitives OK\n";
