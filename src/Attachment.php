<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

use Wildye\EmailParser\Exception\SignatureVerificationException;

final class Attachment
{
    public function __construct(
        public readonly ?string $filename,
        public readonly string $contentType,
        public readonly int $size,
        public readonly ?string $contentId,
        public readonly ?string $disposition,
        /** Base64 content; null when attachments arrive as separate files (multipart) or are left out. */
        private readonly ?string $base64Content,
        /** Hex SHA-256 of the file. */
        public readonly ?string $sha256 = null,
        /** Multipart deliveries: the form part holding the file, e.g. "attachment_0". */
        public readonly ?string $part = null,
        private readonly ?string $filePath = null,
    ) {
    }

    /** The same attachment, with its bytes read from an uploaded file (multipart deliveries). */
    public function withFile(string $path): self
    {
        return new self($this->filename, $this->contentType, $this->size, $this->contentId, $this->disposition, $this->base64Content, $this->sha256, $this->part, $path);
    }

    /** false when the account's attachment setting is "leave them out" (or a multipart file is missing). */
    public function hasContent(): bool
    {
        return $this->base64Content !== null || $this->filePath !== null;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['filename']) ? (string) $data['filename'] : null,
            (string) ($data['content_type'] ?? 'application/octet-stream'),
            (int) ($data['size'] ?? 0),
            isset($data['content_id']) ? (string) $data['content_id'] : null,
            isset($data['disposition']) ? (string) $data['disposition'] : null,
            isset($data['content']) ? (string) $data['content'] : null,
            isset($data['sha256']) ? (string) $data['sha256'] : null,
            isset($data['part']) ? (string) $data['part'] : null,
        );
    }

    /**
     * The decoded file bytes. For multipart deliveries the uploaded file is checked against the signed SHA-256.
     *
     * @throws SignatureVerificationException if a multipart file doesn't match the signed event
     * @throws \LogicException if the content wasn't included
     */
    public function content(): string
    {
        if ($this->base64Content !== null) {
            $decoded = base64_decode($this->base64Content, true);

            return $decoded === false ? '' : $decoded;
        }
        if ($this->filePath === null) {
            throw new \LogicException('This attachment has no content: the account delivers attachments separately or not at all');
        }
        $bytes = file_get_contents($this->filePath);
        if ($bytes === false) {
            throw new \RuntimeException("Couldn't read {$this->filePath}");
        }
        if ($this->sha256 === null || !hash_equals($this->sha256, hash('sha256', $bytes))) {
            throw new SignatureVerificationException("Attachment {$this->part} doesn't match the signed event");
        }

        return $bytes;
    }

    public function isInline(): bool
    {
        return $this->disposition === 'inline' || $this->contentId !== null;
    }

    /**
     * Write the attachment into $directory and return the full path. The name is sanitised so a
     * malicious filename like "../../etc/passwd" can't escape the directory.
     */
    public function saveTo(string $directory, ?string $filename = null): string
    {
        $name = self::safeFilename($filename ?? $this->filename ?? 'attachment');
        $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;
        if (file_put_contents($path, $this->content()) === false) {
            throw new \RuntimeException("Couldn't write attachment to {$path}");
        }

        return $path;
    }

    private static function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\w.\- ]+/u', '_', $name) ?? '';
        $name = ltrim($name, '.');

        return $name === '' ? 'attachment' : $name;
    }
}
