<?php
declare(strict_types=1);

namespace Ksfraser\Ocr;

/**
 * Tesseract OCR adapter with container-first, local-shell fallback.
 *
 * Tries an HTTP POST to a containerized Tesseract endpoint first; if that
 * is unreachable or fails, falls back to shelling out to the local `tesseract`
 * binary. Errors from both attempts are aggregated into a single usable
 * error message when both fail.
 *
 * Set KSF_OCR_TESSERACT_CONTAINER_URL to the container endpoint (e.g.
 * http://tesseract:8080/ocr). Set KSF_OCR_TESSERACT_CMD to force a local
 * binary path. Set KSF_OCR_TESSERACT_LANG for a default language.
 */
class TesseractOcrAdapter implements OcrEngineInterface
{
    /** @var string|null Container endpoint URL */
    private $containerUrl;
    /** @var string|null Forced local tesseract command */
    private $forcedCommand;
    /** @var string|null Default language */
    private $defaultLanguage;

    /**
     * @param string|null $containerUrl   Container endpoint URL (overrides env)
     * @param string|null $defaultLanguage Default OCR language (overrides env)
     */
    public function __construct(?string $containerUrl = null, ?string $defaultLanguage = null)
    {
        $envUrl = getenv('KSF_OCR_TESSERACT_CONTAINER_URL');
        $this->containerUrl = $containerUrl !== null
            ? $containerUrl
            : (($envUrl === false || $envUrl === '') ? null : (string)$envUrl);

        $cmd = getenv('KSF_OCR_TESSERACT_CMD');
        $this->forcedCommand = ($cmd === false || $cmd === '') ? null : (string)$cmd;

        $envLang = getenv('KSF_OCR_TESSERACT_LANG');
        $this->defaultLanguage = $defaultLanguage !== null
            ? $defaultLanguage
            : (($envLang === false || $envLang === '') ? null : (string)$envLang);
    }

    /**
     * @inheritDoc
     */
    public function engineName(): string
    {
        return 'tesseract';
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool
    {
        return $this->containerUrl !== null || $this->resolveLocalCommand() !== null;
    }

    /**
     * @inheritDoc
     */
    public function ocr(string $imagePath, ?string $language = null): OcrResult
    {
        $result = new OcrResult();
        $result->sourcePath = $imagePath;
        $result->engine = 'tesseract';

        if (!file_exists($imagePath)) {
            $result->error = 'Image file not found: ' . $imagePath;
            return $result;
        }

        $lang = $language ?? $this->defaultLanguage ?? 'eng';

        // 1) Try containerized Tesseract first.
        if ($this->containerUrl !== null) {
            $containerResult = $this->ocrViaContainer($imagePath, $lang);
            if ($containerResult->success) {
                return $containerResult;
            }
            // Container failed — record its error and fall through to local.
            $containerError = $containerResult->error;
        } else {
            $containerError = null;
        }

        // 2) Fall back to local shell call.
        $localResult = $this->ocrViaShell($imagePath, $lang);
        if ($localResult->success) {
            return $localResult;
        }

        // 3) Both failed — aggregate into a usable error.
        $parts = [];
        if ($containerError !== null) {
            $parts[] = 'Container Tesseract (' . $this->containerUrl . '): ' . $containerError;
        }
        if ($localResult->error !== '') {
            $parts[] = 'Local Tesseract shell: ' . $localResult->error;
        }
        if (empty($parts)) {
            $parts[] = 'Tesseract OCR failed (no further detail)';
        }

        $result->error = implode('; ', $parts);
        $result->success = false;
        return $result;
    }

    /**
     * POST the image to the containerized Tesseract endpoint.
     *
     * Expected container contract: POST the image file as form field 'image',
     * optional 'lang' field, returns JSON {success: bool, text: string, error?: string}.
     *
     * @return OcrResult
     */
    private function ocrViaContainer(string $imagePath, string $lang): OcrResult
    {
        $result = new OcrResult();
        $result->sourcePath = $imagePath;
        $result->engine = 'tesseract (container)';

        $boundary = '----TesseractFormBoundary' . md5(time());
        $imageContent = file_get_contents($imagePath);
        if ($imageContent === false) {
            $result->error = 'Could not read image file for container upload';
            return $result;
        }

        $mime = mime_content_type($imagePath) ?? 'application/octet-stream';
        $body = "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"image\"; filename=\"" . basename($imagePath) . "\"\r\n";
        $body .= "Content-Type: $mime\r\n\r\n";
        $body .= $imageContent . "\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"lang\"\r\n\r\n";
        $body .= $lang . "\r\n";
        $body .= "--$boundary--\r\n";

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: multipart/form-data; boundary=$boundary\r\n"
                          . "Content-Length: " . strlen($body) . "\r\n"
                          . "Timeout: 30\r\n",
                'content' => $body,
                'timeout' => 30,
            ],
        ]);

        $start = microtime(true);
        $raw = @file_get_contents($this->containerUrl, false, $ctx);
        $latencyMs = (int)((microtime(true) - $start) * 1000);
        $httpCode = null;

        // Extract HTTP response code from wrapper data.
        $wrapper = $http_response_header ?? [];
        foreach ($wrapper as $h) {
            if (stripos($h, 'HTTP/') === 0) {
                preg_match('/HTTP\/[\d.]+ (\d+)/', $h, $m);
                if (isset($m[1])) {
                    $httpCode = (int)$m[1];
                }
            }
        }

        if ($raw === false) {
            $result->error = 'No response from container (connection refused or timeout after ' . $latencyMs . 'ms)';
            return $result;
        }

        $decoded = json_decode($raw, true);
        if ($decoded === null || !is_array($decoded)) {
            $result->error = 'Container returned non-JSON response (HTTP ' . ($httpCode ?? '?') . '): ' . mb_substr(trim($raw), 0, 200);
            return $result;
        }

        if (isset($decoded['success']) && $decoded['success'] === true) {
            $result->success = true;
            $result->text = (string)($decoded['text'] ?? '');
            $result->details['latency_ms'] = $latencyMs;
            $result->details['http_code'] = $httpCode;
            return $result;
        }

        $result->error = (string)($decoded['error'] ?? 'Container reported failure (HTTP ' . ($httpCode ?? '?') . ')');
        $result->details['latency_ms'] = $latencyMs;
        $result->details['http_code'] = $httpCode;
        return $result;
    }

    /**
     * Shell out to the local tesseract binary.
     *
     * @return OcrResult
     */
    private function ocrViaShell(string $imagePath, string $lang): OcrResult
    {
        $result = new OcrResult();
        $result->sourcePath = $imagePath;
        $result->engine = 'tesseract (shell)';

        $cmd = $this->resolveLocalCommand();
        if ($cmd === null) {
            $result->error = 'Local tesseract binary not found';
            return $result;
        }

        $base = tempnam(sys_get_temp_dir(), 'ocr_') . '_base';
        $textFile = $base . '.txt';
        $imageArg = escapeshellarg($imagePath);
        $baseArg = escapeshellarg($base);
        $langArg = escapeshellarg($lang);

        $cmdLine = "$cmd $imageArg $baseArg -l $langArg 2>&1";
        $start = microtime(true);
        $output = [];
        $exitCode = null;
        exec($cmdLine, $output, $exitCode);
        $latencyMs = (int)((microtime(true) - $start) * 1000);

        if ($exitCode !== 0 || !file_exists($textFile)) {
            $result->error = (implode("\n", $output) ?: 'Tesseract exited with code ' . $exitCode)
                . ' (' . $latencyMs . 'ms)';
            @unlink($textFile);
            @unlink($base);
            return $result;
        }

        $text = file_get_contents($textFile);
        @unlink($textFile);
        @unlink($base);

        if ($text === false) {
            $result->error = 'Could not read Tesseract output file';
            return $result;
        }

        $result->success = true;
        $result->text = (string)$text;
        $result->details['latency_ms'] = $latencyMs;
        return $result;
    }

    private function resolveLocalCommand(): ?string
    {
        if ($this->forcedCommand !== null) {
            return $this->forcedCommand;
        }

        if ($this->commandExists('tesseract')) {
            return 'tesseract';
        }

        return null;
    }

    private function commandExists(string $cmd): bool
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $check = 'where ' . escapeshellarg($cmd);
        } else {
            $check = 'command -v ' . escapeshellarg($cmd);
        }
        exec($check . ' 2>&1', $out, $code);
        return $code === 0 && !empty($out);
    }
}
