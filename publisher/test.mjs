import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import http from 'node:http';
import { spawn } from 'node:child_process';
import { generateKeyPairSync, createVerify } from 'node:crypto';

const CLI = new URL('./bin/expo-updates-php.js', import.meta.url).pathname;
function run(args, env) {
  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, [CLI, ...args], { env: { ...process.env, ...env } });
    let out = '', err = '';
    child.stdout.on('data', b => out += b); child.stderr.on('data', b => err += b);
    child.on('close', code => code ? reject(new Error(`${code}: ${out}\n${err}`)) : resolve(out));
  });
}
function listen(server) { return new Promise(resolve => server.listen(0, '127.0.0.1', () => resolve(server.address().port))); }
function close(server) { return new Promise(resolve => server.close(resolve)); }
function body(req) { return new Promise(resolve => { const chunks = []; req.on('data', b => chunks.push(b)); req.on('end', () => resolve(Buffer.concat(chunks))); }); }

test('publisher signs exact manifests and uploads shared assets once', async () => {
  const temp = await mkdtemp(path.join(tmpdir(), 'ota-test-'));
  const { privateKey, publicKey } = generateKeyPairSync('rsa', { modulusLength: 2048, privateKeyEncoding: { type:'pkcs8', format:'pem' }, publicKeyEncoding: { type:'spki', format:'pem' } });
  await mkdir(path.join(temp, 'dist/bundles'), { recursive: true });
  await mkdir(path.join(temp, 'dist/assets'), { recursive: true });
  await writeFile(path.join(temp, 'dist/bundles/ios.js'), 'ios bundle');
  await writeFile(path.join(temp, 'dist/bundles/android.js'), 'android bundle');
  await writeFile(path.join(temp, 'dist/assets/logo.png'), Buffer.from('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000b49444154789c636000020000050001a5f645400000000049454e44ae426082', 'hex'));
  await writeFile(path.join(temp, 'dist/assetmap.json'), '{}');
  await writeFile(path.join(temp, 'dist/metadata.json'), JSON.stringify({ version:0, bundler:'metro', fileMetadata: { ios: { bundle:'bundles/ios.js', assets:[{path:'assets/logo.png',ext:'png'}] }, android: { bundle:'bundles/android.js', assets:[{path:'assets/logo.png',ext:'png'}] } } }));
  await writeFile(path.join(temp, 'config.json'), JSON.stringify({ runtimeVersion:'1', name:'Smoke' }));
  await writeFile(path.join(temp, 'key.pem'), privateKey);
  let uploads = 0, final;
  const server = http.createServer(async (req, res) => {
    const b = await body(req);
    assert.equal(req.headers.authorization, 'Bearer test-token');
    res.setHeader('content-type', 'application/json');
    if (req.url.endsWith('/publishes')) {
      const begin = JSON.parse(b);
      assert.deepEqual(Object.keys(begin.platforms).sort(), ['android','ios']);
      res.end(JSON.stringify({ releaseId:'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', updates:{ ios:{ id:'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', createdAt:'2026-09-23T00:00:00.000Z' }, android:{ id:'cccccccc-cccc-4ccc-8ccc-cccccccccccc', createdAt:'2026-09-23T00:00:00.000Z' } } }));
    } else if (req.url.endsWith('/assets/check')) {
      assert.equal(JSON.parse(b).hashes.length, 3);
      res.end(JSON.stringify({ missing: JSON.parse(b).hashes }));
    } else if (req.url.endsWith('/assets')) {
      uploads++; res.end('{}');
    } else if (req.url.endsWith('/finalize')) {
      final = JSON.parse(b); res.end('{"status":"active"}');
    } else { res.statusCode = 404; res.end('{}'); }
  });
  try {
    const port = await listen(server);
    await run(['publish','--server',`http://localhost:${port}`,'--project','smoke','--export-dir',path.join(temp,'dist'),'--config-json',path.join(temp,'config.json'),'--private-key',path.join(temp,'key.pem')], { OTA_TOKEN:'test-token' });
    assert.equal(uploads, 3);
    for (const platform of ['ios','android']) {
      const entry = final.updates[platform];
      const sig = /sig="([^"]+)"/.exec(entry.signature)?.[1];
      assert.ok(createVerify('RSA-SHA256').update(entry.manifest).end().verify(publicKey, Buffer.from(sig,'base64')));
      const manifest = JSON.parse(entry.manifest);
      assert.equal(manifest.assets[0].hash, JSON.parse(final.updates.ios.manifest).assets[0].hash);
      assert.equal(manifest.runtimeVersion, '1');
    }
  } finally { await close(server); await rm(temp, { recursive:true, force:true }); }
});

test('rollback clones the source bytes under a fresh launch URL and signs it', async () => {
  const temp = await mkdtemp(path.join(tmpdir(), 'ota-clone-'));
  const { privateKey, publicKey } = generateKeyPairSync('rsa', { modulusLength: 2048, privateKeyEncoding: { type:'pkcs8', format:'pem' }, publicKeyEncoding: { type:'spki', format:'pem' } });
  await writeFile(path.join(temp, 'key.pem'), privateKey);
  const digest = Buffer.alloc(32, 7);
  const oldId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
  const newId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
  const oldManifest = { id:oldId, createdAt:'2026-09-20T00:00:00.000Z', runtimeVersion:'1', launchAsset:{ hash:digest.toString('base64url'), key:'bundle', contentType:'application/javascript', url:'old-url' }, assets:[], metadata:{}, extra:{} };
  let submitted;
  const server = http.createServer(async (req, res) => {
    const b = await body(req); res.setHeader('content-type','application/json');
    if (req.url.endsWith(`/updates/${oldId}`)) return res.end(JSON.stringify({ update:{ id:oldId, status:'active', platform:'ios', runtime_version:'1', channel:'production' } }));
    if (req.url.endsWith('/publishes')) {
      assert.equal(JSON.parse(b).platforms.ios.sourceUpdateId, oldId);
      return res.end(JSON.stringify({ releaseId:'cccccccc-cccc-4ccc-8ccc-cccccccccccc', updates:{ ios:{ id:newId, createdAt:'2026-09-23T00:00:00.000Z', sourceManifest:oldManifest } } }));
    }
    if (req.url.endsWith('/finalize')) { submitted = JSON.parse(b); return res.end('{"status":"active"}'); }
    res.statusCode = 404; res.end('{}');
  });
  try {
    const port = await listen(server);
    await run(['rollback','--server',`http://localhost:${port}`,'--project','smoke','--update',oldId,'--private-key',path.join(temp,'key.pem')], { OTA_TOKEN:'test-token' });
    const entry = submitted.updates.ios, manifest = JSON.parse(entry.manifest);
    assert.equal(manifest.launchAsset.url, `http://localhost:${port}/api/v1/projects/smoke/launch/${newId}/${digest.toString('hex')}`);
    assert.equal(manifest.createdAt, '2026-09-23T00:00:00.000Z');
    const sig = /sig="([^"]+)"/.exec(entry.signature)[1];
    assert.ok(createVerify('RSA-SHA256').update(entry.manifest).end().verify(publicKey, Buffer.from(sig,'base64')));
  } finally { await close(server); await rm(temp, { recursive:true, force:true }); }
});
