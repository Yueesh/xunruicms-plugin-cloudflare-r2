# Cloudflare R2

后台「附件设置 → 存储策略 → 添加」选择 Cloudflare R2。

1. 在 Cloudflare 创建存储桶及具备该桶对象读写权限的 R2 S3 API 凭据。
2. 填写 Access Key ID、Secret Access Key、Bucket 和控制台给出的完整 HTTPS S3 Endpoint。支持默认、EU 和 FedRAMP Endpoint。
3. 配置存储桶公开自定义域名或 r2.dev 地址，在「附件访问URL」填写公开地址（以 `/` 结尾）。API Endpoint 不能用作附件访问URL。
4. 若指定目录为 `media`，附件访问URL应为 `https://files.example.com/media/`。
5. 保存策略，使用后台「测试」按钮，再将需要的附件字段设置为此策略。

插件沿用 CMS 图片压缩、水印处理。服务器需要 cURL、HTTPS CA 证书和 hash 扩展。单请求上传，不支持分片上传或私有附件签名链接；受 PHP、CMS 和 R2 单请求大小与超时限制。已有附件不会迁移。
