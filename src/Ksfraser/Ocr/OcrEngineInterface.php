<?php
declare(strict_types=1);

namespace Ksfraser\Ocr;

/**
 * Result of an OCR operation.
 */
class OcrResult
{
    /** @var string Extracted text */
    public $text = '';
    /** @var bool Whether OCR succeeded */
    public $success = false;
    /** @var string Error message when success === false */
    public $error = '';
    /** @var string Source file path */
    public $sourcePath = '';
    /** @var string Engine used (e.g. tesseract) */
    public $engine = '';
    /** @var array Per-page or per-line data (implementation-specific) */
    public $details = [];
}

/**
 * Interface for OCR engines.
 *
 * Implementations may shell out to Tesseract, call a cloud API, etc.
 */
interface OcrEngineInterface
{
    /**
     * Return a human-readable engine name.
     *
     * @return string
     */
    public function engineName(): string;

    /**
     * Check whether the engine is available on this host.
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * Extract text from an image file.
     *
     * @param string $imagePath Path to JPG/PNG/TIFF
     * @param string|null $language ISO language code (e.g. 'eng'), null = default
     * @return OcrResult
     */
    public function ocr(string $imagePath, ?string $language = null): OcrResult;
}
