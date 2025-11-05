<?php

namespace App\Exceptions;

use Exception;

class GoogleEarthEngineException extends Exception
{
    protected array $context;
    protected ?int $httpStatus;
    protected ?string $geeErrorCode;

    public function __construct(
        string $message,
        array $context = [],
        ?int $httpStatus = null,
        ?string $geeErrorCode = null,
        ?Exception $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
        $this->httpStatus = $httpStatus;
        $this->geeErrorCode = $geeErrorCode;
    }

    /**
     * Get the context data for this exception.
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get the HTTP status code if available.
     */
    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * Get the GEE error code if available.
     */
    public function getGeeErrorCode(): ?string
    {
        return $this->geeErrorCode;
    }

    /**
     * Convert the exception to an array for JSON responses.
     */
    public function toArray(): array
    {
        $array = [
            'error' => $this->getMessage(),
            'context' => $this->context,
        ];

        if ($this->httpStatus) {
            $array['http_status'] = $this->httpStatus;
        }

        if ($this->geeErrorCode) {
            $array['gee_error_code'] = $this->geeErrorCode;
        }

        return $array;
    }

    /**
     * Create an exception for authentication failures.
     */
    public static function authenticationFailed(string $details = '', ?Exception $previous = null): self
    {
        return new self(
            'Google Earth Engine authentication failed' . ($details ? ': ' . $details : ''),
            ['type' => 'authentication', 'details' => $details],
            null,
            'AUTH_ERROR',
            $previous
        );
    }

    /**
     * Create an exception for API request failures.
     */
    public static function apiRequestFailed(
        int $httpStatus,
        string $message,
        array $responseData = [],
        ?Exception $previous = null
    ): self {
        return new self(
            "GEE API request failed: HTTP {$httpStatus} - {$message}",
            [
                'type' => 'api_request',
                'http_status' => $httpStatus,
                'response' => $responseData,
            ],
            $httpStatus,
            $responseData['error']['code'] ?? null,
            $previous
        );
    }

    /**
     * Create an exception for missing data.
     */
    public static function noDataAvailable(string $area, ?int $year = null, array $context = []): self
    {
        $message = "No data available for {$area}";
        if ($year) {
            $message .= " in year {$year}";
        }

        return new self(
            $message,
            array_merge([
                'type' => 'no_data',
                'area' => $area,
                'year' => $year,
            ], $context)
        );
    }

    /**
     * Create an exception for timeout errors.
     */
    public static function timeout(int $seconds, array $context = []): self
    {
        return new self(
            "GEE request timed out after {$seconds} seconds",
            array_merge([
                'type' => 'timeout',
                'timeout_seconds' => $seconds,
            ], $context),
            504
        );
    }

    /**
     * Create an exception for invalid geometry.
     */
    public static function invalidGeometry(string $details, array $context = []): self
    {
        return new self(
            "Invalid geometry provided: {$details}",
            array_merge([
                'type' => 'invalid_geometry',
                'details' => $details,
            ], $context)
        );
    }
}



