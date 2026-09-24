# Expo側設定と実機試験

SDK 55以降のExpoアプリで `expo-updates` をインストールします。`examples/ota-smoke` はVersion A/B確認用の最小例です。`app.json` のURL、bundle identifier、Android packageを実環境へ変更し、公開証明書を同じフォルダに置きます。署名設定はNative Buildへ組み込まれるため、設定後にiOS/AndroidのRelease Buildを作ります。Expo GoではOTAの受入試験はできません。

`runtimeVersion` はNative互換性を示します。JSだけなら維持し、Native Module・SDK・Native設定を変えたら更新します。Publisherがpolicy objectを解決できないときは `--runtime-ios` と `--runtime-android` を明示します。buildに埋め込まれた値との一致を実機で確認してください。`preview`を使うならNative Buildに `expo-channel-name: preview` を設定します。

1. Version AでiOSとAndroidのRelease Buildを起動します。
2. `App.js` をVersion Bへ変更し、Publisherで `production` に公開します。
3. `verify` でManifest、署名、Assetを確認します。アプリを再起動または `Updates.reloadAsync()` で更新します。
4. runtimeVersionを変えたBuildで旧Updateが配信されないこと、previewがproductionへ出ないことを確認します。
5. Bを配信した後にVersion Cを配信し、Bを`rollback --update`で再発行して戻ることを確認します。
6. `rollback --embedded`をiOS/Androidそれぞれに発行し、埋込版へ戻ることを確認します。
7. Asset破損・誤署名に対して更新が拒否され、正常版から起動できることを確認します。

署名なしの開発試験では、サーバーの `require_signing` を明示的にfalseにし、Expo側の署名設定も外してください。本番は署名を有効にします。
