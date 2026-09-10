<?php
declare(strict_types=1);

namespace CoNotes;

/**
 * Anything thrown as an ApiError becomes a clean { error: { code, message } }
 * response. Anything else becomes a 500 with a generic message - stack traces
 * never reach the client.
 */
class ApiError extends \RuntimeException
{
    public int $status;

    // Not $code: \Exception already declares that property (untyped, and for
    // PHP's own exception code, not ours), and PHP forbids a subclass giving
    // an inherited untyped property a native type. This is a different thing
    // anyway - a string API error code like 'not_found', not PHP's exception
    // code - so a distinct name is clearer, not just a workaround.
    public string $errorCode;

    /** @var array<string,mixed> Extra top-level fields merged into the response body. */
    public array $extra;

    /** @param array<string,mixed> $extra */
    public function __construct(int $status, string $code, string $message, array $extra = [])
    {
        parent::__construct($message);
        $this->status    = $status;
        $this->errorCode = $code;
        $this->extra     = $extra;
    }

    public static function badRequest(string $message, string $code = 'bad_request'): self
    {
        return new self(400, $code, $message);
    }

    public static function unauthorized(string $message = 'Sign in to continue.'): self
    {
        return new self(401, 'unauthenticated', $message);
    }

    public static function forbidden(string $message = 'You are not a member of this board.'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self(404, 'not_found', $message);
    }

    /**
     * Optimistic-concurrency failure. Carries the current server state so the
     * client can show a real conflict instead of silently overwriting.
     *
     * @param array<string,mixed> $current
     */
    public static function conflict(array $current): self
    {
        return new self(
            409,
            'version_conflict',
            'This card changed while you were editing it.',
            ['current' => $current]
        );
    }

    public static function tooLarge(string $message): self
    {
        return new self(413, 'payload_too_large', $message);
    }
}
