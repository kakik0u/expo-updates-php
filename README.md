# Expo Updates PHP Server

Expo Updates Protocol v1 準拠のiOS / Android用 Expo OTAサーバー

PHP 8.1+、MariaDB/MySQL、Apacheのいわゆるレンタルサーバー(Shared Hosting)環境で動作するOTA配信サーバーです。

## インストール

1. DBとDBユーザーを用意し、`schema.sql` をインポートする。
2. `server/config/config.example.php` を `server/config/config.php` にコピーし、DB接続、`base_url`、`storage_path` を設定します。`server/public` だけをドキュメントルートに設定します。`config` と `storage` は公開ディレクトリの外に置いてください。
3. 開発環境で `openssl req -x509 -newkey rsa:4096 -sha256 -nodes -keyout signing.key -out certificate.pem -days 3650 -subj '/CN=OTA signing'` を実行します。秘密鍵 `signing.key` はサーバーに送らず、生成した公開証明書のみ `server/config/certificate.pem` に配置します。
4. `server/` をアップロードし、PHPの `pdo_mysql`、`openssl`、`fileinfo` を有効にします。(レンタルサーバー環境だともうすでに有効化されている可能性大)
5. 開発環境で `node publisher/bin/expo-updates-php.js token-sql --project myapp` を実行します。(myappはアプリ名) 表示されたSQLコマンド部分をphpMyAdminで実行し、表示された `OTA_TOKEN` を安全に保存します。
6. Expoアプリに `updates.url`、`runtimeVersion`、`codeSigningCertificate`、`codeSigningMetadata`、`expo-channel-name` を設定します。初回は新しいRelease Native Buildが必要です。
7. `OTA_TOKEN=... node publisher/bin/expo-updates-php.js publish --server https://example.com/ota --project myapp --app /path/to/expo-app --private-key /private/signing.key` でOTAアップデートを公開します。
8. `GET /api/v1/projects/SLUG/manifest` に必要なExpoヘッダーを付けて接続をテストします。Update未登録なら204が正常です。ApacheがAuthorizationをPHPへ渡すことも確認してください。共有ホストのアップロード制限に合わせ、1 Assetが `upload_max_filesize` 以下となるようにします。

Expo側の設定については、詳しくは [Expo設定](docs/expo-setup.md) を参照してください。

## よく使う操作

```bash
export OTA_TOKEN='...'
node publisher/bin/expo-updates-php.js verify --server https://example.com/ota --project myapp --platform ios --runtime 1 --certificate certificate.pem
node publisher/bin/expo-updates-php.js list --server https://example.com/ota --project myapp
node publisher/bin/expo-updates-php.js rollback --server https://example.com/ota --project myapp --update UPDATE_UUID --private-key signing.key
node publisher/bin/expo-updates-php.js rollback --embedded --server https://example.com/ota --project myapp --platform ios --runtime 1 --private-key signing.key
```

`rollback --update` は指定した古いUpdateのAssetを参照する新しいIDと日時のUpdateを発行します。`promote --update ID --from preview --to production` も同じく再署名し、Assetをコピーしません。署名鍵は `OTA_PRIVATE_KEY_PATH`、Tokenは `OTA_TOKEN` でも指定できます。

# ライセンス

MITライセンス
