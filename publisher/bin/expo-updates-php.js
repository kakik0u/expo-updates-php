#!/usr/bin/env node
import { readFile, mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { createHash, createSign, createVerify, randomBytes, X509Certificate } from 'node:crypto';

function options(args) {
  const out = { _: [] };
  for (let i = 0; i < args.length; i++) {
    if (!args[i].startsWith('--')) { out._.push(args[i]); continue; }
    const [k, inline] = args[i].slice(2).split('=', 2);
    out[k] = inline ?? (args[i + 1]?.startsWith('--') || args[i + 1] === undefined ? true : args[++i]);
  }
  return out;
}
const o = options(process.argv.slice(2));
const command = o._[0];
function required(name) { if (!o[name] || o[name] === true) throw new Error(`--${name} required`); return o[name]; }
function server() { const url = required('server').replace(/\/$/, ''); const u = new URL(url); if (u.protocol !== 'https:' && !(u.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(u.hostname))) throw new Error('HTTPS server required'); return url; }
function project() { const v = required('project'); if (!/^[A-Za-z0-9_-]+$/.test(v)) throw new Error('Invalid project slug'); return v; }
function admin() { return `${server()}/api/admin/v1/projects/${project()}`; }
function token() { const t = process.env.OTA_TOKEN; if (!t) throw new Error('OTA_TOKEN is required'); return t; }
async function request(url, { method = 'GET', body, form, auth = true, headers = {} } = {}) {
  const h = { ...headers }; if (auth) h.Authorization = `Bearer ${token()}`;
  if (body !== undefined) { h['Content-Type'] = 'application/json'; body = JSON.stringify(body); }
  const response = await fetch(url, { method, headers: h, body: form ?? body, redirect: 'error' });
  if (!response.ok) throw new Error(`${response.status} ${url}: ${(await response.text()).slice(0, 1000)}`);
  const type = response.headers.get('content-type') ?? '';
  return type.includes('json') ? response.json() : response;
}
function run(cwd, ...args) {
  const r = spawnSync('npx', ['--no-install', 'expo', ...args], { cwd, stdio: ['inherit', 'pipe', 'inherit'], encoding: 'utf8' });
  if (r.status !== 0) throw new Error(`npx expo ${args.join(' ')} failed: ${r.stdout}`);
  return r.stdout;
}
function b64Hash(bytes) { return createHash('sha256').update(bytes).digest('base64url'); }
function hexHash(bytes) { return createHash('sha256').update(bytes).digest('hex'); }
const mimes = { png:'image/png', jpg:'image/jpeg', jpeg:'image/jpeg', gif:'image/gif', webp:'image/webp', svg:'image/svg+xml', ttf:'font/ttf', otf:'font/otf', woff:'font/woff', woff2:'font/woff2', mp3:'audio/mpeg', mp4:'video/mp4', json:'application/json', js:'application/javascript', hbc:'application/javascript' };
function exportPath(root, relative) {
  if (typeof relative !== 'string' || relative.startsWith('/') || relative.includes('\\')) throw new Error(`Invalid export path: ${relative}`);
  const full = path.resolve(root, relative);
  if (!full.startsWith(path.resolve(root) + path.sep)) throw new Error(`Export path escapes dist: ${relative}`);
  return full;
}
async function fileAsset(root, relative, ext, launch, platform, uid) {
  const bytes = await readFile(exportPath(root, relative));
  const hash = b64Hash(bytes), hex = hexHash(bytes), key = createHash('md5').update(bytes).digest('hex');
  const contentType = launch ? 'application/javascript' : (mimes[ext?.toLowerCase()] ?? 'application/octet-stream');
  const url = `${server()}/api/v1/projects/${project()}/${launch ? `launch/${uid}/${hex}` : `assets/${hex}`}`;
  const descriptor = { hash, key, contentType, ...(launch ? {} : { fileExtension: `.${ext}` }), url };
  return { descriptor, bytes, ext: launch ? '' : `.${ext}` };
}
function signature(bytes) {
  const keyPath = o['private-key'] ?? process.env.OTA_PRIVATE_KEY_PATH;
  if (!keyPath) return null;
  const key = requireRead(keyPath);
  const sig = createSign('RSA-SHA256').update(bytes, 'utf8').end().sign(key, 'base64');
  const keyId = o['key-id'] ?? 'main';
  if (!/^[A-Za-z0-9_-]+$/.test(keyId)) throw new Error('Invalid key ID');
  return `sig="${sig}", keyid="${keyId}", alg="rsa-v1_5-sha256"`;
}
let keyCache = new Map();
function requireRead(file) { if (!keyCache.has(file)) keyCache.set(file, requireFile(file)); return keyCache.get(file); }
// Native sync read keeps signing deterministic and avoids reloading the key per platform.
import { readFileSync as requireFile } from 'node:fs';
function runtime(config, platform) {
  const override = o[`runtime-${platform}`] ?? o.runtime;
  const value = override ?? config.runtimeVersion;
  if (typeof value !== 'string' || !value) throw new Error(`Resolved runtimeVersion for ${platform} required: use --runtime-${platform}`);
  return value;
}
async function upload(assets) {
  const unique = new Map(assets.map(a => [a.descriptor.hash, a]));
  const hashes = [...unique.keys()];
  for (let n = 0; n < hashes.length; n += 500) {
    const { missing } = await request(`${admin()}/assets/check`, { method: 'POST', body: { hashes: hashes.slice(n, n + 500) } });
    for (const hash of missing) {
      const a = unique.get(hash);
      const form = new FormData(); form.set('hash', hash); form.set('contentType', a.descriptor.contentType); form.set('fileExtension', a.ext);
      form.set('file', new Blob([a.bytes], { type: a.descriptor.contentType }), hash);
      await request(`${admin()}/assets`, { method: 'POST', form });
      process.stdout.write(`uploaded ${hash}\n`);
    }
  }
}
async function begin(platforms, channel, message) {
  return request(`${admin()}/publishes`, { method: 'POST', body: { platforms, channel, message } });
}
async function finalize(releaseId, manifests) {
  return request(`${admin()}/publishes/${releaseId}/finalize`, { method: 'POST', body: { updates: manifests } });
}
async function publish() {
  const app = path.resolve(o.app ?? '.');
  let temp;
  const dist = o['export-dir'] ? path.resolve(o['export-dir']) : (temp = await mkdtemp(path.join(tmpdir(), 'expo-ota-')));
  try {
    if (!o['export-dir']) run(app, 'export', '--platform', 'all', '--dump-assetmap', '--output-dir', dist);
    const metadata = JSON.parse(await readFile(path.join(dist, 'metadata.json'), 'utf8'));
    if (metadata.version !== 0 || metadata.bundler !== 'metro' || !metadata.fileMetadata || typeof metadata.fileMetadata !== 'object') throw new Error('Unsupported Expo metadata.json format');
    const assetMap = JSON.parse(await readFile(path.join(dist, 'assetmap.json'), 'utf8'));
    if (!assetMap || typeof assetMap !== 'object' || Array.isArray(assetMap)) throw new Error('Invalid Expo assetmap.json');
    const config = JSON.parse(o['config-json'] ? await readFile(o['config-json'], 'utf8') : run(app, 'config', '--type', 'public', '--json'));
    const expoConfig = config.expo ?? config;
    const selected = ['ios', 'android'].filter(p => metadata.fileMetadata[p]);
    if (!selected.length) throw new Error('No native platforms in export');
    const platforms = Object.fromEntries(selected.map(p => [p, { runtimeVersion: runtime(expoConfig, p) }]));
    const started = await begin(platforms, o.channel ?? 'production', o.message ?? '');
    const assets = [], manifests = {};
    for (const p of selected) {
      const spec = metadata.fileMetadata[p], update = started.updates[p];
      if (!spec.bundle || !Array.isArray(spec.assets)) throw new Error(`Invalid Expo metadata for ${p}`);
      const launch = await fileAsset(dist, spec.bundle, '', true, p, update.id); assets.push(launch);
      const other = [];
      for (const item of spec.assets) {
        if (typeof item.ext !== 'string' || !/^[a-zA-Z0-9]{1,20}$/.test(item.ext)) throw new Error(`Invalid asset extension for ${p}`);
        const a = await fileAsset(dist, item.path, item.ext, false, p, update.id); assets.push(a); other.push(a.descriptor);
      }
      const manifest = { id: update.id, createdAt: update.createdAt, runtimeVersion: platforms[p].runtimeVersion, launchAsset: launch.descriptor, assets: other, metadata: {}, extra: { expoClient: expoConfig } };
      const bytes = JSON.stringify(manifest);
      manifests[p] = { manifest: bytes, signature: signature(bytes) };
    }
    await upload(assets);
    console.log(await finalize(started.releaseId, manifests));
  } finally { if (temp) await rm(temp, { recursive: true, force: true }); }
}
async function clone(mode) {
  const id = required('update');
  const inspected = await request(`${admin()}/updates/${id}`);
  const old = inspected.update;
  if (old.status !== 'active') throw new Error('Source update is not active');
  const channel = mode === 'promote' ? required('to') : (o.channel ?? old.channel);
  if (mode === 'promote' && o.from && old.channel !== o.from) throw new Error('Source channel mismatch');
  const started = await begin({ [old.platform]: { runtimeVersion: old.runtime_version, sourceUpdateId: id } }, channel, `${mode} ${id}`);
  const update = started.updates[old.platform];
  const manifest = update.sourceManifest;
  manifest.id = update.id; manifest.createdAt = update.createdAt;
  manifest.launchAsset.url = `${server()}/api/v1/projects/${project()}/launch/${update.id}/${Buffer.from(manifest.launchAsset.hash, 'base64url').toString('hex')}`;
  const bytes = JSON.stringify(manifest);
  console.log(await finalize(started.releaseId, { [old.platform]: { manifest: bytes, signature: signature(bytes) } }));
}
async function embedded() {
  const platform = required('platform'), runtimeVersion = required('runtime');
  if (!['ios','android'].includes(platform)) throw new Error('platform must be ios or android');
  const body = JSON.stringify({ type: 'rollBackToEmbedded', parameters: { commitTime: new Date().toISOString() } });
  console.log(await request(`${admin()}/directives`, { method: 'POST', body: { platform, runtimeVersion, channel: o.channel ?? 'production', body, signature: signature(body) } }));
}
function parseMultipart(response, bytes) {
  const type = response.headers.get('content-type') ?? '';
  const boundary = /boundary="?([^";]+)"?/.exec(type)?.[1];
  if (!boundary) throw new Error(`Unexpected content type ${type}`);
  const parts = bytes.split(`--${boundary}`);
  const section = parts.find(p => p.includes('name="manifest"') || p.includes('name="directive"'));
  if (!section) throw new Error('No manifest or directive part');
  const split = section.indexOf('\r\n\r\n');
  if (split < 0) throw new Error('Malformed multipart');
  return { headers: section.slice(0, split), body: section.slice(split + 4).replace(/\r\n$/, '') };
}
async function verify() {
  const platform = required('platform'), runtimeVersion = required('runtime');
  const url = `${server()}/api/v1/projects/${project()}/manifest`;
  const response = await fetch(url, { headers: { 'Expo-Protocol-Version':'1', 'Expo-Platform':platform, 'Expo-Runtime-Version':runtimeVersion, 'Expo-Channel-Name': o.channel ?? 'production', 'Accept':'multipart/mixed', ...(o.certificate ? { 'Expo-Expect-Signature':`sig, keyid="${o['key-id'] ?? 'main'}", alg="rsa-v1_5-sha256"` } : {}) } });
  if (response.status === 204) { if (response.headers.get('expo-protocol-version') !== '1' || response.headers.get('expo-sfv-version') !== '0') throw new Error('Invalid protocol response'); console.log('No update'); return; }
  if (!response.ok || response.headers.get('expo-protocol-version') !== '1' || response.headers.get('expo-sfv-version') !== '0') throw new Error(`Invalid protocol response: ${response.status}`);
  const part = parseMultipart(response, await response.text());
  const value = JSON.parse(part.body);
  if (o.certificate) {
    // Expo SDK 57 iOS looks up this MIME part header with a case-sensitive dictionary key.
    const sig = /(?:^|\r\n)expo-signature:\s*sig="([A-Za-z0-9+/=]+)"/.exec(part.headers)?.[1];
    if (!sig) throw new Error('Missing signature');
    const cert = new X509Certificate(await readFile(o.certificate));
    if (!createVerify('RSA-SHA256').update(part.body, 'utf8').end().verify(cert.publicKey, Buffer.from(sig, 'base64'))) throw new Error('Bad signature');
  }
  if (value.type === 'rollBackToEmbedded') { console.log('Embedded rollback directive verified'); return; }
  if (!/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(value.id ?? '') || !Number.isFinite(Date.parse(value.createdAt)) || value.runtimeVersion !== runtimeVersion || !Array.isArray(value.assets) || !value.launchAsset || typeof value.metadata !== 'object' || typeof value.extra !== 'object') throw new Error('Invalid manifest');
  if (process.env.OTA_TOKEN) {
    const { update } = await request(`${admin()}/updates/${value.id}`);
    if (update.channel !== (o.channel ?? 'production') || update.platform !== platform || update.runtime_version !== runtimeVersion) throw new Error('Server selected the wrong update scope');
  }
  for (const a of [value.launchAsset, ...value.assets]) {
    if (typeof a.url !== 'string' || typeof a.hash !== 'string' || typeof a.key !== 'string' || typeof a.contentType !== 'string') throw new Error('Invalid manifest asset');
    const response = await fetch(a.url); if (!response.ok) throw new Error(`Asset HTTP ${response.status}: ${a.url}`);
    if ((response.headers.get('content-type') ?? '').split(';')[0].trim() !== a.contentType) throw new Error(`Asset MIME mismatch: ${a.url}`);
    const bytes = Buffer.from(await response.arrayBuffer()); if (b64Hash(bytes) !== a.hash) throw new Error(`Asset hash mismatch: ${a.url}`);
  }
  console.log(`Verified update ${value.id}, ${value.assets.length + 1} assets; bsdiff: not implemented`);
}
async function tokenSql() {
  const slug = project(); const t = randomBytes(32).toString('base64url'); const hash = createHash('sha256').update(t).digest('hex');
  console.log(`INSERT INTO projects (slug, name) VALUES ('${slug}', '${slug}') ON DUPLICATE KEY UPDATE name = name;`);
  console.log(`INSERT INTO api_tokens (project_id, name, token_hash) SELECT id, 'publisher', '${hash}' FROM projects WHERE slug = '${slug}';`);
  console.error(`OTA_TOKEN=${t}`);
}
async function main() {
  if (command === 'publish') return publish();
  if (command === 'promote') return clone('promote');
  if (command === 'rollback') return o.embedded ? embedded() : clone('rollback');
  if (command === 'verify') return verify();
  if (command === 'token-sql') return tokenSql();
  if (command === 'list') return console.log(await request(`${admin()}/updates`));
  if (command === 'inspect') return console.log(await request(`${admin()}/updates/${o._[1] ?? required('update')}`));
  if (command === 'gc') return console.log(await request(`${admin()}/gc`, { method: 'POST', body: {} }));
  if (command === 'delete') return console.log(await request(`${admin()}/updates/${required('update')}`, { method: 'DELETE' }));
  console.log('Commands: token-sql, publish, list, inspect, promote, rollback, verify, gc, delete');
}
main().catch(e => { console.error(e.message); process.exitCode = 1; });
