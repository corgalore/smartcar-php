<?php

namespace Smartcar;

class Response {

    private array $httpResponse;
    private array $responseBody;
    private array $permissionsDenied;
    private string $error;
    private bool $successful = false;

    public function __construct( $httpResponse )
    {
        $this->httpResponse = $httpResponse;

        $this->responseBody = json_decode( $httpResponse['body'], true );

        if ( 'OK' !== $this->httpResponse['response']['message'] ) {

            $this->error = sprintf("Smartcar API Error: %s %s - Data: %s %s %s",
                $this->httpResponse['response']['code'],
                $resp['response']['message'] ?? '',
                $this->responseBody['statusCode'] ?? '',
                $this->responseBody['type'] ?? '',
                $this->responseBody['description'] ?? '',
            );

            return;
        }

        $this->successful = true;
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getBody(): array
    {
        return $this->responseBody ?? [];
    }

    public function getError(): string
    {
        return $this->error;
    }

    public function getPermissionsDenied(): array
    {
        return $this->permissionsDenied;
    }

    public function getBatchResults() : array {

        $results = [];

        if ( ! $this->isBatch() ) {
            return $results;
        }

        foreach ($this->responseBody['responses'] as $response ) {
            $path = substr( $response['path'], 1 );
            $results[ $path ] = $response['body'];
        }

        return $results;
    }

    public function getResult( string $propertyName = '' ) : array {

        if ( ! empty( $propertyName ) && array_key_exists( $propertyName, $this->responseBody ) )
            return $this->responseBody[$propertyName];

        return $this->getBody();
    }

    public function needsReauthentication() : bool
    {

        $this->permissionsDenied = [];

        $needs_validation = function( $body ) {

            return $body['type'] ?? '' == 'PERMISSION'
                && ( $body['resolution']['type'] ?? '' == 'REAUTHENTICATE' );
        };

        $responses = isset( $this->responseBody['responses'] ) ? $this->responseBody['responses'] : [ ['body' => $this->responseBody] ];

        foreach ( $responses as $response ) {
            if ( $needs_validation( $response['body'] ) ) {
                if ( ! in_array( $response['path'], $this->permissionsDenied ) )
                    $permissions_denied[] = $response['path'];
            }
        }

        return ! empty( $permissions_denied );
    }

    public function isBatch()  {
        return isset( $this->responseBody['responses'] );
    }

    public static function emptyResponse(): Response
    {
        return new self([]);
    }
}