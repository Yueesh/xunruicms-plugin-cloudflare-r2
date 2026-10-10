<?php namespace Phpcmf\ThirdParty\Storage;

/** Cloudflare R2 S3-compatible storage (Signature V4, no SDK required). */
class R2 {
    protected $attachment;
    protected $filename;
    protected $config;

    public function init($attachment, $filename) {
        $this->attachment = $attachment;
        $this->filename = trim(str_replace('\\', '/', $filename), '/');
        $this->config = isset($attachment['value']) ? $attachment['value'] : [];
        return $this;
    }

    protected function validate() {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('R2 存储需要 PHP cURL 扩展');
        }
        foreach (['accessKeyId', 'accessKeySecret', 'bucket', 'endpoint'] as $field) {
            if (empty($this->config[$field]) || !is_string($this->config[$field])) {
                throw new \RuntimeException('R2 配置缺少字段：'.$field);
            }
            $this->config[$field] = trim($this->config[$field]);
            if ($this->config[$field] === '' || preg_match('/[\r\n]/', $this->config[$field])) {
                throw new \RuntimeException('R2 配置字段无效：'.$field);
            }
        }
        $endpoint = parse_url($this->config['endpoint']);
        if (!$endpoint || !isset($endpoint['scheme'], $endpoint['host'])
            || strtolower($endpoint['scheme']) !== 'https'
            || !preg_match('/^[a-z0-9-]+(?:\.(?:eu|fedramp))?\.r2\.cloudflarestorage\.com$/i', $endpoint['host'])
            || isset($endpoint['user']) || isset($endpoint['pass']) || isset($endpoint['query'])
            || isset($endpoint['fragment']) || isset($endpoint['port'])
            || (isset($endpoint['path']) && trim($endpoint['path'], '/') !== '')) {
            throw new \RuntimeException('R2 Endpoint 必须是完整的 HTTPS R2 S3 API 地址，不可填写公开访问域名');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $this->config['bucket'])) {
            throw new \RuntimeException('R2 Bucket 名称无效');
        }
        if ($this->filename === '' || preg_match('/[\x00-\x1f\x7f]/', $this->objectKey())) {
            throw new \RuntimeException('R2 对象路径无效');
        }
        foreach (explode('/', $this->objectKey()) as $part) {
            if ($part === '.' || $part === '..') {
                throw new \RuntimeException('R2 对象路径不能包含 . 或 .. 目录');
            }
        }
        $this->config['endpoint'] = 'https://'.strtolower($endpoint['host']);
    }

    protected function objectKey() {
        $prefix = isset($this->config['path']) ? trim(str_replace('\\', '/', $this->config['path']), '/') : '';
        return ($prefix === '' ? '' : $prefix.'/').$this->filename;
    }

    protected function encodePath($path) {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    protected function signedRequest($method, $hash, $date) {
        $host = parse_url($this->config['endpoint'], PHP_URL_HOST);
        $uri = '/'.$this->encodePath($this->config['bucket'].'/'.$this->objectKey());
        $headers = 'host:'.$host."\n".'x-amz-content-sha256:'.$hash."\n".'x-amz-date:'.$date."\n";
        $names = 'host;x-amz-content-sha256;x-amz-date';
        $canonical = $method."\n".$uri."\n\n".$headers."\n".$names."\n".$hash;
        $day = substr($date, 0, 8);
        $scope = $day.'/auto/s3/aws4_request';
        $string = "AWS4-HMAC-SHA256\n".$date."\n".$scope."\n".hash('sha256', $canonical);
        $key = hash_hmac('sha256', $day, 'AWS4'.$this->config['accessKeySecret'], true);
        foreach (['auto', 's3', 'aws4_request'] as $part) {
            $key = hash_hmac('sha256', $part, $key, true);
        }
        return [
            'url' => $this->config['endpoint'].$uri,
            'headers' => [
                'Host: '.$host,
                'x-amz-content-sha256: '.$hash,
                'x-amz-date: '.$date,
                'Authorization: AWS4-HMAC-SHA256 Credential='.$this->config['accessKeyId'].'/'.$scope
                    .', SignedHeaders='.$names.', Signature='.hash_hmac('sha256', $string, $key),
            ],
        ];
    }

    protected function request($method, $file = null) {
        $hash = $file === null ? hash('sha256', '') : hash_file('sha256', $file);
        if ($hash === false) {
            throw new \RuntimeException('R2 无法读取待上传文件');
        }
        $signed = $this->signedRequest($method, $hash, gmdate('Ymd\THis\Z'));
        $curl = curl_init($signed['url']);
        $stream = null;
        try {
            $headers = $signed['headers'];
            $headers[] = 'Expect:';
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if ($file !== null) {
                $stream = fopen($file, 'rb');
                if (!$stream) {
                    throw new \RuntimeException('R2 无法打开待上传文件');
                }
                $mime = 'application/octet-stream';
                if (class_exists('finfo')) {
                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($file) ?: $mime;
                }
                $headers[] = 'Content-Type: '.$mime;
                curl_setopt_array($curl, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $stream,
                    CURLOPT_INFILESIZE => filesize($file)]);
            }
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            $body = curl_exec($curl);
            if ($body === false) {
                // Do not expose credentials or request headers in errors.
                throw new \RuntimeException('R2 网络请求失败（cURL '.curl_errno($curl).'）');
            }
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($status < 200 || $status >= 300) {
                $code = '';
                if (preg_match('/<Code>([A-Za-z0-9_]+)<\/Code>/', $body, $match)) {
                    $code = '：'.$match[1];
                }
                throw new \RuntimeException('R2 请求失败（HTTP '.$status.'）'.$code);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            curl_close($curl);
        }
    }

    public function upload($type, $data, $watermark) {
        $temporary = null;
        try {
            $this->validate();
            if (empty($this->attachment['url']) || !filter_var($this->attachment['url'], FILTER_VALIDATE_URL)
                || !preg_match('#^https?://#i', $this->attachment['url'])) {
                throw new \RuntimeException('R2 请配置有效的附件公开访问 URL');
            }
            $directory = WRITEPATH.'attach/';
            if (!is_dir($directory) && !dr_mkdirs($directory) && !is_dir($directory)) {
                throw new \RuntimeException('R2 无法创建临时目录');
            }
            $extension = pathinfo($this->filename, PATHINFO_EXTENSION);
            $temporary = $directory.'r2-'.bin2hex(random_bytes(16))
                .($extension !== '' ? '.'.$extension : '');
            $storage = new \Phpcmf\Library\Storage();
            $rt = $storage->uploadfile($type, $data, $temporary, $watermark, $this->attachment);
            if (!$rt['code']) {
                return $rt;
            }
            clearstatcache(true, $temporary);
            $md5 = md5_file($temporary);
            $size = filesize($temporary);
            $this->request('PUT', $temporary);
            return dr_return_data(1, 'ok', [
                'url' => rtrim($this->attachment['url'], '/').'/'.$this->encodePath($this->filename),
                'md5' => $md5, 'size' => $size, 'info' => $rt['data'],
            ]);
        } catch (\Exception $e) {
            return dr_return_data(0, $e->getMessage());
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function delete() {
        try {
            $this->validate();
            $this->request('DELETE');
        } catch (\Exception $e) {
            log_message('error', 'Cloudflare R2 存储删除失败：'.$e->getMessage());
        }
    }
}
