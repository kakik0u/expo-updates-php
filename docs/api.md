# API

全URLは `base_url` 配下です。Admin APIは `Authorization: Bearer OTA_TOKEN` 必須。Tokenはprojectに紐付きます。

| Method | Path | 内容 |
|---|---|---|
| GET | `/api/v1/projects/{slug}/manifest` | Protocol v1 multipart Manifest/Directive、または204 |
| GET | `/api/v1/projects/{slug}/assets/{sha256hex}` | 不変Asset |
| GET | `/api/v1/projects/{slug}/launch/{updateId}/{sha256hex}` | Full launch bundle |
| POST | `/api/admin/v1/projects/{slug}/publishes` | Releaseとuploading Updateを作成 |
| POST | `/api/admin/v1/projects/{slug}/assets/check` | Base64URL SHA-256の存在確認 |
| POST | `/api/admin/v1/projects/{slug}/assets` | multipartで1 Assetアップロード |
| POST | `/api/admin/v1/projects/{slug}/publishes/{releaseId}/finalize` | 全Platformをトランザクションで公開 |
| GET | `/api/admin/v1/projects/{slug}/updates` | 最新100件 |
| GET | `/api/admin/v1/projects/{slug}/updates/{id}` | Update詳細 |
| DELETE | `/api/admin/v1/projects/{slug}/updates/{id}` | Updateを無効化 |
| POST | `/api/admin/v1/projects/{slug}/directives` | 署名済みrollback directive |
| POST | `/api/admin/v1/projects/{slug}/gc` | 単一Project構成で24時間以上経過した未参照Assetを回収 |

Manifest requestは `Expo-Protocol-Version: 1`、`Expo-Platform`、`Expo-Runtime-Version`、`Accept: multipart/mixed` を必要とします。`Expo-Channel-Name`省略時は設定のdefault channelです。`Expo-Expect-Signature`がある場合は対応する署名をmanifest/directive partに付けます。

Beginのbodyは `{ "channel":"production", "message":"...", "platforms":{ "ios":{ "runtimeVersion":"1" } } }`。既存Updateの内容を複製するときはplatform entryに`sourceUpdateId`を追加します。Begin responseには新しい`releaseId`と各platformの`id`/`createdAt`、複製時は`sourceManifest`を含みます。

Finalizeのbodyは `{ "updates": { "ios": { "manifest":"<serialized JSON>", "signature":"sig=..., keyid=..., alg=..." } } }`。Manifest文字列は署名したバイト列のまま保存します。全Asset、URL、runtime、ID、作成日時、署名を検証した後にのみactiveになります。
