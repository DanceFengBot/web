把 Android 最新安装包放在本目录，并命名为：

    app-latest.apk

然后编辑站点根目录的 app-update.json：
- versionCode 改为新版本号（必须大于旧包）
- versionName 改为新版本名
- force 设为 true 时用户必须更新
- url 保持 download/app-latest.apk

注意：Android 安装包必须使用固定签名；签名变化会导致覆盖安装失败。
