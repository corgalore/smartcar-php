<?php

namespace Smartcar;

class AuthClient
{
    const AUTH_API_URL = 'https://auth.smartcar.com/oauth/';
    private array $config;

    public function __construct( array $config )
    {
        $this->config = array_merge( [
            'client_id' => '',
            'client_secret' => '',
            'redirect_uri' => '',
            'mode' => 'live'
        ], $config );
    }

    private function get_api_url( $endpoint ): string
    {
        return self::AUTH_API_URL . $endpoint;
    }

    private function get_authorization_header_value(): string
    {
        return 'Basic ' . base64_encode( $this->config['client_id'].':'.$this->config['client_secret'] );
    }

    public function refresh_access_token( string $code ) : array {

        $body = [
            'grant_type=refresh_token',
            "refresh_token=$code"
        ];

        return $this->get_token( $code, $body );
    }

    public function get_access_token( string $code ) : array {

        $body = [
            'grant_type=authorization_code',
            "code=$code"
        ];

        return $this->get_token( $code, $body );
    }

    private function get_token( string $code, $body ) : array {

        $body = array_merge(
            $body,
            ['redirect_uri=' . $this->config['redirect_uri']]
        );

        $args = [
            'headers' => [
                'Authorization' => $this->get_authorization_header_value(),
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'user-agent' => 'VADA USA - Fleet Control App',
            'body' => implode( '&', $body )
        ];

        $resp = wp_safe_remote_post( $this->get_api_url( 'token' ), $args );

        // In case of a server error, log and throw
        if ( is_a( $resp, 'WP_Error' ) ) {
            error_log( $resp->get_error_message() );
            throw new \Exception( $resp->get_error_message() );
        }

        // Start reading the POST response
        $response = $resp['response'];

        // Get any details from the BODY
        $body = json_decode( $resp['body'], true );

        if ( 'OK' !== $response['message'] ) {

            // Can be:
            // invalid_client: Invalid or incorrect client credentials
            // invalid_grant: Invalid or expired authorization code

            // Log results
            $msg = sprintf("Smartcar API Error: %s %s %s %s",
                $response['code'],
                $response['message'],
                $body['error'],
                $body['error_description']
            );
            error_log( $msg );
        }

        return [
            'success' => 'OK' == $response['message'],
            ...$response,
            'credentials' => [
                ...$body
            ]
        ];
    }
}