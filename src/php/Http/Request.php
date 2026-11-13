<?php

declare(strict_types=1);

namespace RuneCloud\Http;

use JsonException;

/**
 * Provides explicit credentials and bounded JSON input to protected routes.
 */
final class Request
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Contains the Authorization header supplied by the entry point.
     *
     * @var string
     */
    private readonly string $authorization;

    /**
     * Contains the request content type.
     *
     * @var string
     */
    private readonly string $contentType;

    /**
     * Contains at most the accepted JSON size plus one detection byte.
     *
     * @var string
     */
    private readonly string $body;

    /**
     * PHP upload records supplied by the entry point.
     *
     * @var array
     */
    private array $uploads;

    /**
     * Whether PHP rejected the entire multipart body.
     *
     * @var bool
     */
    private bool $bodyOverflow;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives request input without accessing globals.
     *
     * @param string $authorization Authorization header.
     * @param string $contentType Content-Type header.
     * @param string $body Request bytes, read with a bounded limit by the entry point.
     * @param array<string, mixed> $uploads PHP upload records.
     * @param bool $bodyOverflow Whether the multipart body exceeded PHP limits.
     */
    public function __construct(
        string $authorization = '',
        string $contentType = '',
        string $body = '',
        array $uploads = [],
        bool $bodyOverflow = false
    )
    {
        $this->authorization = $authorization;
        $this->contentType = $contentType;
        $this->body = $body;
        $this->uploads = $uploads;
        $this->bodyOverflow = $bodyOverflow;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Indicates whether credentials were supplied, including invalid credentials.
     * @return bool
     */
    public function hasAuthorization(): bool
    {
        return $this->authorization !== '';
    }

    /**
     * Indicates whether JSON metadata was provided for an optional request body.
     * @return bool
     */
    public function hasBody(): bool
    {
        return $this->body !== '';
    }

    /**
     * Extracts a bearer token from the Authorization header only.
     *
     * @return string
     * @throws HttpException When credentials are absent or malformed.
     */
    public function bearerToken(): string
    {
        if (preg_match('/^Bearer ([^\s]+)$/iD', $this->authorization, $match) !== 1) {
            throw new HttpException('unauthorized', 401, ['WWW-Authenticate' => 'Bearer']);
        }
        
        return $match[1];
    }

    /**
     * Decodes a JSON object within the 64 KiB metadata request limit.
     *
     * @return array<string, mixed>
     * @throws HttpException When the body is oversized, malformed or has an unsupported media type.
     */
    public function json(): array
    {
        if (strlen($this->body) > 65536) {
            throw new HttpException('payload_too_large', 413);
        }
        
        if (strtolower(trim(explode(';', $this->contentType, 2)[0])) !== 'application/json') {
            throw new HttpException('unsupported_media_type', 415);
        }
        
        try {
            $value = json_decode($this->body, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException('invalid_json', 400);
        }
        
        if (!$value instanceof \stdClass) {
            throw new HttpException('json_object_required', 400);
        }
        
        return (array) $value;
    }    
    
    /**
     * Returns multipart upload records after request-level validation.
     *
     * @param list<string> $allowed Accepted multipart field names.
     * @return array<string, array<string, mixed>>
     * @throws HttpException When PHP rejected the body or fields are invalid.
     */
    public function files(array $allowed = ['game', 'icon']): array
    {
        if ($this->bodyOverflow) {
            throw new HttpException('upload_too_large', 413);
        }
        
        if (!str_starts_with(strtolower($this->contentType), 'multipart/form-data;')) {
            throw new HttpException('multipart_required', 415);
        }
        
        if (array_diff(array_keys($this->uploads), $allowed) !== []) {
            throw new HttpException('unknown_upload_field', 422);
        }
        
        $files = [];
        foreach ($this->uploads as $kind => $upload) {
            if (!is_array($upload) || !is_int($upload['error'] ?? null)) {
                throw new HttpException('invalid_upload_field', 422);
            }
            if ($upload['error'] !== UPLOAD_ERR_NO_FILE) {
                $files[$kind] = $upload;
            }
        }
        
        if ($files === []) {
            throw new HttpException('file_required', 422);
        }
        
        return $files;
    }
}
