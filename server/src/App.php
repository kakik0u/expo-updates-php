<?php
declare(strict_types=1);

final class HttpError extends RuntimeException {
    public function __construct(public readonly int $status, string $message) { parent::__construct($message); }
}

final class OtaApp {
    private PDO $db;
    private string $base;
    private string $storage;
    private array $headers;
    private const VARY = 'Expo-Protocol-Version, Expo-Platform, Expo-Runtime-Version, Expo-Channel-Name, Expo-Current-Update-ID, Expo-Embedded-Update-ID, Expo-Expect-Signature, Accept';

    public function __construct(private array $config) {
        $this->base = rtrim((string)($config['base_url'] ?? ''), '/');
        if (!str_starts_with($this->base, 'https://') && !str_starts_with($this->base, 'http://localhost')) {
            throw new RuntimeException('base_url must use HTTPS');
        }
        $this->storage = rtrim((string)$config['storage_path'], '/');
        $d = $config['database'];
        $dsn = 'mysql:host=' . $d['host'] . ';dbname=' . $d['name'] . ';charset=utf8mb4';
        $this->db = new PDO($dsn, $d['user'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $this->db->exec("SET time_zone = '+00:00'");
        $this->headers = array_change_key_case(function_exists('getallheaders') ? (getallheaders() ?: []) : [], CASE_LOWER);
        if (!isset($this->headers['authorization']) && isset($_SERVER['HTTP_AUTHORIZATION'])) $this->headers['authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
    }

    public function handle(): void {
        try {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $prefix = parse_url($this->base, PHP_URL_PATH) ?: '';
            if ($prefix !== '' && $prefix !== '/' && str_starts_with($path, rtrim($prefix, '/') . '/')) $path = substr($path, strlen(rtrim($prefix, '/')));
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if ($method === 'GET' && preg_match('~^/api/v1/projects/([a-zA-Z0-9_-]+)/manifest$~D', $path, $m)) { $this->manifest($m[1]); return; }
            if ($method === 'GET' && preg_match('~^/api/v1/projects/([a-zA-Z0-9_-]+)/assets/([a-f0-9]{64})$~D', $path, $m)) { $this->asset($m[2], $m[1]); return; }
            if ($method === 'GET' && preg_match('~^/api/v1/projects/([a-zA-Z0-9_-]+)/launch/([a-f0-9-]{36})/([a-f0-9]{64})$~D', $path, $m)) { $this->asset($m[3], $m[1], $m[2]); return; }
            if (preg_match('~^/api/admin/v1/projects/([a-zA-Z0-9_-]+)(.*)$~D', $path, $m)) {
                $project = $this->project($m[1]);
                $this->auth((int)$project['id']);
                $tail = $m[2];
                if ($method === 'POST' && $tail === '/publishes') { $this->begin($project); return; }
                if ($method === 'POST' && $tail === '/assets/check') { $this->checkAssets(); return; }
                if ($method === 'POST' && $tail === '/assets') { $this->uploadAsset(); return; }
                if ($method === 'POST' && preg_match('~^/publishes/([a-f0-9-]{36})/finalize$~D', $tail, $x)) { $this->finalize($project, $x[1]); return; }
                if ($method === 'GET' && $tail === '/updates') { $this->listUpdates($project); return; }
                if ($method === 'GET' && preg_match('~^/updates/([a-f0-9-]{36})$~D', $tail, $x)) { $this->inspect($project, $x[1]); return; }
                if ($method === 'POST' && $tail === '/directives') { $this->directive($project); return; }
                if ($method === 'DELETE' && preg_match('~^/updates/([a-f0-9-]{36})$~D', $tail, $x)) { $this->deleteUpdate($project, $x[1]); return; }
                if ($method === 'POST' && $tail === '/gc') { $this->gc(); return; }
            }
            throw new HttpError(404, 'Not found');
        } catch (HttpError $error) {
            error_log('OTA request rejected HTTP ' . $error->status . ': ' . $error->getMessage());
            $this->json(['error' => $error->getMessage()], $error->status);
        }
    }

    private function h(string $name): ?string { return $this->headers[strtolower($name)] ?? null; }
    private function q(string $sql, array $params = []): PDOStatement {
        $s = $this->db->prepare($sql); $s->execute($params); return $s;
    }
    private function json(array $body, int $status = 200): void {
        http_response_code($status); header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store'); echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    private function input(): array {
        if (!str_starts_with(strtolower($this->h('content-type') ?? ''), 'application/json')) throw new HttpError(415, 'Expected JSON');
        $raw = file_get_contents('php://input');
        if ($raw === false || strlen($raw) > 8 * 1024 * 1024) throw new HttpError(413, 'JSON body too large');
        try { $object = json_decode($raw, false, 512, JSON_THROW_ON_ERROR); $v = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new HttpError(400, 'Invalid JSON'); }
        if (!($object instanceof stdClass)) throw new HttpError(400, 'Expected JSON object');
        return $v;
    }
    private function project(string $slug): array {
        $p = $this->q('SELECT * FROM projects WHERE slug = ?', [$slug])->fetch();
        if (!$p) throw new HttpError(404, 'Project not found'); return $p;
    }
    private function auth(int $projectId): void {
        $header = $this->h('authorization') ?? '';
        if (!preg_match('/^Bearer ([A-Za-z0-9_-]{32,256})$/', $header, $m)) { error_log('OTA authentication failure'); throw new HttpError(401, 'Bearer token required'); }
        $hash = hash('sha256', $m[1]);
        $row = $this->q('SELECT id FROM api_tokens WHERE project_id = ? AND token_hash = ? AND revoked_at IS NULL', [$projectId, $hash])->fetch();
        if (!$row) { error_log('OTA authentication failure'); throw new HttpError(403, 'Invalid token'); }
        $this->q('UPDATE api_tokens SET last_used_at = NOW(6) WHERE id = ?', [$row['id']]);
    }
    private static function uuid(): string {
        $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 15) | 64); $b[8] = chr((ord($b[8]) & 63) | 128);
        $h = bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
    private static function iso(string $sqlDate): string {
        return (new DateTimeImmutable($sqlDate, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
    private static function validLabel(mixed $s): bool { return is_string($s) && (bool)preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $s); }
    private static function hashHex(mixed $s): string {
        if (!is_string($s) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $s)) throw new HttpError(400, 'Invalid asset hash');
        $raw = base64_decode(strtr($s, '-_', '+/') . '=', true);
        if ($raw === false || strlen($raw) !== 32 || rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') !== $s) throw new HttpError(400, 'Invalid asset hash');
        return bin2hex($raw);
    }
    private static function b64Hash(string $hex): string { return rtrim(strtr(base64_encode(hex2bin($hex)), '+/', '-_'), '='); }
    private function signed(string $bytes, mixed $signature): bool {
        if (!is_string($signature) || !preg_match('/^sig="([A-Za-z0-9+\/=]+)", keyid="([A-Za-z0-9_-]+)", alg="rsa-v1_5-sha256"$/D', $signature, $m)) return false;
        if ($m[2] !== ($this->config['signing_key_id'] ?? 'main')) return false;
        $cert = $this->config['signing_certificate_path'] ?? null;
        if (!is_string($cert) || !is_file($cert)) return false;
        $sig = base64_decode($m[1], true);
        return $sig !== false && openssl_verify($bytes, $sig, file_get_contents($cert), OPENSSL_ALGO_SHA256) === 1;
    }
    private function requireSignature(string $bytes, mixed $signature): void {
        if (($this->config['require_signing'] ?? true) || $signature !== null) {
            if (!$this->signed($bytes, $signature)) throw new HttpError(422, 'Invalid or missing signature');
        }
    }
    private function protocolHeaders(): void {
        header('Expo-Protocol-Version: 1'); header('Expo-SFV-Version: 0');
        header('Cache-Control: private, max-age=0'); header('Vary: ' . self::VARY);
    }
    private function multipart(string $name, string $body, ?string $signature): void {
        $boundary = 'expo-' . bin2hex(random_bytes(16));
        $this->protocolHeaders(); header('Content-Type: multipart/mixed; boundary=' . $boundary);
        echo '--' . $boundary . "\r\n";
        echo 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n";
        echo "Content-Type: application/json; charset=utf-8\r\n";
        // SDK 57 iOS retains MIME part header casing and looks up this exact key.
        if ($signature !== null) echo 'expo-signature: ' . $signature . "\r\n";
        echo "\r\n" . $body . "\r\n--" . $boundary . "--\r\n";
    }
    private function manifest(string $slug): void {
        if ($this->h('expo-protocol-version') !== '1') throw new HttpError(406, 'Protocol v1 required');
        $platform = $this->h('expo-platform'); $runtime = $this->h('expo-runtime-version');
        if (!in_array($platform, ['ios', 'android'], true) || !is_string($runtime) || $runtime === '' || strlen($runtime) > 255) throw new HttpError(400, 'Invalid platform or runtime version');
        $accept = strtolower($this->h('accept') ?? '');
        if (!str_contains($accept, 'multipart/mixed') && !str_contains($accept, '*/*')) throw new HttpError(406, 'multipart/mixed required');
        $channel = $this->h('expo-channel-name') ?? $this->config['default_channel'] ?? 'production';
        if (!self::validLabel($channel)) throw new HttpError(400, 'Invalid channel');
        $p = $this->project($slug);
        $params = [$p['id'], $platform, $runtime, $channel];
        $u = $this->q("SELECT id, created_at, manifest_json, manifest_signature FROM updates WHERE project_id = ? AND platform = ? AND runtime_version = ? AND channel = ? AND status = 'active' ORDER BY created_at DESC, id DESC LIMIT 1", $params)->fetch();
        $d = $this->q('SELECT body_json, signature, created_at FROM directives WHERE project_id = ? AND platform = ? AND runtime_version = ? AND channel = ? AND active = 1 ORDER BY created_at DESC, id DESC LIMIT 1', $params)->fetch();
        $expect = $this->h('expo-expect-signature');
        if ($expect !== null && preg_match('/keyid="([^"]+)"/', $expect, $key) && $key[1] !== ($this->config['signing_key_id'] ?? 'main')) throw new HttpError(406, 'Signing key unavailable');
        if ($d && (!$u || $d['created_at'] > $u['created_at'])) {
            if ($this->h('expo-current-update-id') === $this->h('expo-embedded-update-id') && $this->h('expo-embedded-update-id') !== null) { $this->protocolHeaders(); http_response_code(204); return; }
            if ($expect !== null && !$d['signature']) throw new HttpError(406, 'Signed directive unavailable');
            $this->multipart('directive', $d['body_json'], $expect !== null ? $d['signature'] : null); return;
        }
        if (!$u || $this->h('expo-current-update-id') === $u['id']) { $this->protocolHeaders(); http_response_code(204); return; }
        if ($expect !== null && !$u['manifest_signature']) throw new HttpError(406, 'Signed update unavailable');
        $this->multipart('manifest', $u['manifest_json'], $expect !== null ? $u['manifest_signature'] : null);
    }
    private function asset(string $hash, string $slug, ?string $updateId = null): void {
        $p = $this->project($slug);
        if ($updateId !== null) {
            $r = $this->q("SELECT a.* FROM assets a JOIN updates u ON u.launch_asset_id = a.id WHERE u.project_id = ? AND u.id = ? AND a.hash = ?", [$p['id'], $updateId, $hash])->fetch();
        } else {
            $r = $this->q("SELECT a.* FROM assets a JOIN update_assets ua ON ua.asset_id = a.id JOIN updates u ON ua.update_id = u.id WHERE u.project_id = ? AND ua.is_launch_asset = 0 AND a.hash = ? LIMIT 1", [$p['id'], $hash])->fetch();
        }
        if (!$r) throw new HttpError(404, 'Asset not found');
        $file = $this->storage . '/' . $r['storage_path'];
        if (!is_file($file)) throw new HttpError(503, 'Asset unavailable');
        header('Content-Type: ' . $r['content_type']);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . (string)filesize($file));
        readfile($file);
    }
    private function begin(array $p): void {
        $v = $this->input(); $channel = $v['channel'] ?? 'production';
        if (!self::validLabel($channel) || !is_array($v['platforms'] ?? null) || !$v['platforms'] || count($v['platforms']) > 2) throw new HttpError(400, 'Invalid publish');
        $id = self::uuid(); $updates = [];
        $this->db->beginTransaction();
        try {
            $this->q('INSERT INTO releases (id, project_id, channel, message) VALUES (?, ?, ?, ?)', [$id, $p['id'], $channel, substr((string)($v['message'] ?? ''), 0, 500)]);
            foreach ($v['platforms'] as $platform => $data) {
                if (!in_array($platform, ['ios','android'], true) || !is_array($data) || !is_string($data['runtimeVersion'] ?? null) || $data['runtimeVersion'] === '' || strlen($data['runtimeVersion']) > 255) throw new HttpError(400, 'Invalid platform/runtime');
                $source = $data['sourceUpdateId'] ?? null;
                if ($source !== null) {
                    $old = $this->q("SELECT * FROM updates WHERE id = ? AND project_id = ? AND platform = ? AND runtime_version = ? AND status = 'active'", [$source, $p['id'], $platform, $data['runtimeVersion']])->fetch();
                    if (!$old) throw new HttpError(404, 'Source update not found');
                }
                $uid = self::uuid();
                $this->q('INSERT INTO updates (id, release_id, project_id, platform, runtime_version, channel, source_update_id) VALUES (?, ?, ?, ?, ?, ?, ?)', [$uid, $id, $p['id'], $platform, $data['runtimeVersion'], $channel, $source]);
                $created = $this->q('SELECT created_at FROM updates WHERE id = ?', [$uid])->fetchColumn();
                $updates[$platform] = ['id' => $uid, 'createdAt' => self::iso($created)];
                if ($source !== null) $updates[$platform]['sourceManifest'] = json_decode($old['manifest_json'], true, 512, JSON_THROW_ON_ERROR);
            }
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
        error_log('OTA publish begin ' . $id);
        $this->json(['releaseId' => $id, 'updates' => $updates], 201);
    }
    private function checkAssets(): void {
        $v = $this->input(); $hashes = $v['hashes'] ?? null;
        if (!is_array($hashes) || count($hashes) > 1000) throw new HttpError(400, 'Invalid hashes');
        $missing = [];
        foreach ($hashes as $h) {
            $hex = self::hashHex($h);
            $r = $this->q('SELECT storage_path FROM assets WHERE hash = ?', [$hex])->fetch();
            if (!$r || !is_file($this->storage . '/' . $r['storage_path']) || hash_file('sha256', $this->storage . '/' . $r['storage_path']) !== $hex) $missing[] = $h;
        }
        $this->json(['missing' => array_values(array_unique($missing))]);
    }
    private function uploadAsset(): void {
        $h = $_POST['hash'] ?? null; $hex = self::hashHex($h);
        $mime = $_POST['contentType'] ?? null; $ext = $_POST['fileExtension'] ?? '';
        if (!is_string($mime) || !preg_match('~^[a-zA-Z0-9.+-]+/[a-zA-Z0-9.+-]+$~D', $mime) || !is_string($ext) || ($ext !== '' && !preg_match('/^\.[a-zA-Z0-9]{1,20}$/D', $ext))) throw new HttpError(400, 'Invalid asset metadata');
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new HttpError(400, 'Upload failed');
        if (hash_file('sha256', $f['tmp_name']) !== $hex) { error_log('OTA asset hash mismatch'); throw new HttpError(422, 'Asset hash mismatch'); }
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if ($detected === false) throw new HttpError(422, 'Unknown MIME type');
        $compatible = $detected === $mime
            || ($mime === 'application/javascript' && in_array($detected, ['text/plain','text/javascript','application/x-javascript','application/octet-stream'], true))
            || ($mime === 'image/svg+xml' && in_array($detected, ['text/xml','application/xml','text/plain'], true))
            || (str_starts_with($mime, 'font/') && in_array($detected, ['font/sfnt','application/octet-stream','application/x-font-ttf','application/x-font-otf','application/font-sfnt','application/x-font-sfnt','application/vnd.ms-opentype','application/font-woff'], true))
            || ($mime === 'application/octet-stream' && $detected === 'application/octet-stream');
        if (!$compatible) throw new HttpError(422, 'MIME type mismatch');
        $relative = 'assets/' . substr($hex, 0, 2) . '/' . $hex;
        $full = $this->storage . '/' . $relative;
        if (!is_dir(dirname($full)) && !mkdir(dirname($full), 0770, true) && !is_dir(dirname($full))) throw new RuntimeException('Storage not writable');
        if (!is_file($full) || hash_file('sha256', $full) !== $hex) {
            $temp = $full . '.' . bin2hex(random_bytes(8)) . '.tmp';
            if (!move_uploaded_file($f['tmp_name'], $temp)) throw new RuntimeException('Cannot move uploaded file');
            if (hash_file('sha256', $temp) !== $hex) { unlink($temp); throw new HttpError(422, 'Asset hash mismatch'); }
            if (!rename($temp, $full)) { unlink($temp); throw new RuntimeException('Cannot store asset'); }
        }
        $size = filesize($full);
        $this->q('INSERT INTO assets (hash, size, content_type, file_extension, storage_path) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id', [$hex, $size, $mime, $ext ?: null, $relative]);
        $this->json(['hash' => $h, 'size' => $size], 201);
    }
    private function validateAsset(array $a, array $p, string $uid, bool $launch): array {
        foreach (['hash','key','contentType','url'] as $field) if (!is_string($a[$field] ?? null) || $a[$field] === '') throw new HttpError(422, 'Invalid manifest asset');
        $hex = self::hashHex($a['hash']);
        $expected = $this->base . '/api/v1/projects/' . $p['slug'] . ($launch ? '/launch/' . $uid . '/' . $hex : '/assets/' . $hex);
        if ($a['url'] !== $expected) throw new HttpError(422, 'Asset URL mismatch');
        if (strlen($a['key']) > 255 || !preg_match('/^[A-Za-z0-9._-]+$/D', $a['key'])) throw new HttpError(422, 'Invalid asset key');
        $row = $this->q('SELECT * FROM assets WHERE hash = ?', [$hex])->fetch();
        if (!$row || !is_file($this->storage . '/' . $row['storage_path'])) throw new HttpError(422, 'Asset missing');
        if (hash_file('sha256', $this->storage . '/' . $row['storage_path']) !== $hex) throw new HttpError(422, 'Stored asset hash mismatch');
        if ($row['content_type'] !== $a['contentType']) throw new HttpError(422, 'Asset MIME mismatch');
        if (!$launch && ($a['fileExtension'] ?? '') !== ($row['file_extension'] ?? '')) throw new HttpError(422, 'Asset extension mismatch');
        return $row;
    }
    private function finalize(array $p, string $releaseId): void {
        $v = $this->input();
        if (!is_array($v['updates'] ?? null)) throw new HttpError(400, 'Missing updates');
        $this->db->beginTransaction();
        try {
            $rows = $this->q('SELECT * FROM updates WHERE release_id = ? AND project_id = ? FOR UPDATE', [$releaseId, $p['id']])->fetchAll();
            if (!$rows || count($rows) !== count($v['updates'])) throw new HttpError(422, 'Update set mismatch');
            foreach ($rows as $row) {
                if ($row['status'] !== 'uploading') throw new HttpError(409, 'Publish already finalized');
                $entry = $v['updates'][$row['platform']] ?? null;
                if (!is_array($entry) || !is_string($entry['manifest'] ?? null)) throw new HttpError(422, 'Missing manifest');
                $bytes = $entry['manifest'];
                if (strlen($bytes) > 8 * 1024 * 1024) throw new HttpError(413, 'Manifest too large');
                try { $object = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR); $m = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR); } catch (JsonException) { throw new HttpError(422, 'Invalid manifest JSON'); }
                if (!($object instanceof stdClass) || !($object->launchAsset ?? null) instanceof stdClass || !is_array($object->assets ?? null) || !($object->metadata ?? null) instanceof stdClass || !($object->extra ?? null) instanceof stdClass) throw new HttpError(422, 'Invalid manifest shape');
                if (!is_array($m) || ($m['id'] ?? null) !== $row['id'] || ($m['runtimeVersion'] ?? null) !== $row['runtime_version'] || ($m['createdAt'] ?? null) !== self::iso($row['created_at']) || !is_array($m['assets'] ?? null) || !is_array($m['launchAsset'] ?? null) || !is_array($m['metadata'] ?? null) || !is_array($m['extra'] ?? null)) throw new HttpError(422, 'Manifest mismatch');
                $signature = $entry['signature'] ?? null;
                $this->requireSignature($bytes, $signature);
                $launch = $this->validateAsset($m['launchAsset'], $p, $row['id'], true);
                $this->q('INSERT INTO update_assets (update_id, asset_id, asset_key, is_launch_asset, sort_order) VALUES (?, ?, ?, 1, 0)', [$row['id'], $launch['id'], $m['launchAsset']['key']]);
                foreach ($m['assets'] as $order => $a) {
                    if (!is_array($a)) throw new HttpError(422, 'Invalid asset');
                    $asset = $this->validateAsset($a, $p, $row['id'], false);
                    $this->q('INSERT INTO update_assets (update_id, asset_id, asset_key, is_launch_asset, sort_order) VALUES (?, ?, ?, 0, ?)', [$row['id'], $asset['id'], $a['key'], $order]);
                }
                $this->q("UPDATE updates SET manifest_json = ?, manifest_signature = ?, launch_asset_id = ?, status = 'active' WHERE id = ?", [$bytes, $signature, $launch['id'], $row['id']]);
            }
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
        error_log('OTA publish finalize ' . $releaseId);
        $this->json(['releaseId' => $releaseId, 'status' => 'active']);
    }
    private function listUpdates(array $p): void {
        $rows = $this->q('SELECT id, release_id, platform, runtime_version, channel, created_at, status, source_update_id FROM updates WHERE project_id = ? ORDER BY created_at DESC LIMIT 100', [$p['id']])->fetchAll();
        foreach ($rows as &$r) $r['createdAt'] = self::iso($r['created_at']);
        $this->json(['updates' => $rows]);
    }
    private function inspect(array $p, string $id): void {
        $r = $this->q('SELECT * FROM updates WHERE id = ? AND project_id = ?', [$id, $p['id']])->fetch();
        if (!$r) throw new HttpError(404, 'Update not found');
        $r['createdAt'] = self::iso($r['created_at']);
        $r['manifest'] = $r['manifest_json'] ? json_decode($r['manifest_json'], true, 512, JSON_THROW_ON_ERROR) : null;
        unset($r['manifest_json'], $r['manifest_signature']); $this->json(['update' => $r]);
    }
    private function directive(array $p): void {
        $v = $this->input();
        foreach (['platform','runtimeVersion','channel','body'] as $field) if (!isset($v[$field])) throw new HttpError(400, 'Missing directive field');
        if (!in_array($v['platform'], ['ios','android'], true) || !self::validLabel($v['channel']) || !is_string($v['runtimeVersion']) || $v['runtimeVersion'] === '') throw new HttpError(400, 'Invalid directive scope');
        $bytes = $v['body']; try { $d = is_string($bytes) ? json_decode($bytes, true, 512, JSON_THROW_ON_ERROR) : null; } catch (JsonException) { throw new HttpError(422, 'Invalid directive JSON'); }
        if (!is_array($d) || ($d['type'] ?? null) !== 'rollBackToEmbedded' || !is_string($d['parameters']['commitTime'] ?? null) || !strtotime($d['parameters']['commitTime'])) throw new HttpError(422, 'Invalid rollback directive');
        $signature = $v['signature'] ?? null; $this->requireSignature($bytes, $signature);
        $this->q('INSERT INTO directives (project_id, platform, runtime_version, channel, type, body_json, signature) VALUES (?, ?, ?, ?, ?, ?, ?)', [$p['id'], $v['platform'], $v['runtimeVersion'], $v['channel'], 'rollBackToEmbedded', $bytes, $signature]);
        error_log('OTA embedded rollback ' . $p['slug']); $this->json(['status' => 'active'], 201);
    }
    private function deleteUpdate(array $p, string $id): void {
        $this->q("UPDATE updates SET status = 'disabled' WHERE id = ? AND project_id = ?", [$id, $p['id']]);
        error_log('OTA disable update ' . $id); $this->json(['status' => 'disabled']);
    }
    private function gc(): void {
        if ((int)$this->q('SELECT COUNT(*) FROM projects')->fetchColumn() !== 1) throw new HttpError(409, 'GC requires a single-project installation');
        // Keep stored assets immutable; GC is deliberately conservative and skips all referenced assets.
        $rows = $this->q('SELECT a.id, a.storage_path FROM assets a LEFT JOIN update_assets ua ON ua.asset_id = a.id LEFT JOIN updates u ON u.launch_asset_id = a.id WHERE ua.asset_id IS NULL AND u.id IS NULL AND a.created_at < NOW(6) - INTERVAL 1 DAY')->fetchAll();
        $removed = 0;
        foreach ($rows as $r) {
            $this->q('DELETE FROM assets WHERE id = ?', [$r['id']]);
            $file = $this->storage . '/' . $r['storage_path']; if (is_file($file)) unlink($file);
            $removed++;
        }
        $this->json(['removed' => $removed]);
    }
}
