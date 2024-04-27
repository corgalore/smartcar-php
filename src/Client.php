<?php

namespace Smartcar;

use Smartcar\AuthClient;
use Smartcar\Response;

class Client
{
    const API_URL = 'https://api.smartcar.com/v2.0/';
    private array $credentials;
    private array $listeners;

    public function __construct( array $credentials )
    {
        $this->credentials = array_merge( [
            'access_token' => '',
            'refresh_token' => '',
            'token_type' => 'Bearer',
            'expiration' => -1
        ], $credentials );

        $this->listeners = [];
    }

    public function get_vehicles(): Response
    {
        return $this->request(
            'vehicles',
            ['method' => 'GET']
        );
    }

    public function get_vehicle_info( string $id ) : Response
    {
        return $this->request(
            'vehicles/' . $id,
            ['method' => 'GET']
        );
    }

    public function get_vehicle_location( string $id ): Response
    {
        return $this->request(
            'vehicles/' . $id . '/location',
            ['method' => 'GET']
        );
    }

    public function get_batch( string $id, array $endpoints ) : Response
    {
        $requests = array_map( function( $endpoint ) {
            if ( '/' !== $endpoint[0] )
                $endpoint = '/' . $endpoint;
            return [ 'path' => $endpoint ];
        }, $endpoints );

        $args = [
            'method' => 'POST',
            'headers' => [
                'Authorization' => $this->get_authorization_header_value(),
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([ 'requests' => $requests ])
        ];

        return $this->request(
            'vehicles/' . $id . '/batch',
            $args
        );
    }

    private function request( string $endpoint, array $args = [] ): Response
    {
        $this->maybe_refresh_access_token();

        $args = wp_parse_args( $args, [
            'headers' => [
                'Authorization' => $this->get_authorization_header_value()
            ]
        ] );

        $url = $this->get_api_url( $endpoint );

        $http = new \WP_Http();
        $resp = $http->request( $url, $args );

        // Check for network error
        if ( is_a( $resp, 'WP_Error' ) ) {

            $this->logError( $resp->get_error_message() );
            return Response::emptyResponse();
        }

        return $this->parse_response( $resp );
    }

    private function parse_response( $resp ) : Response
    {
        $response = new Response( $resp );

        if ( ! $response->isSuccessful() ) {
            $this->trigger( 'request/failed', [ 'response' => $resp ] );
            $this->logError( $response->getError()  );
        }

        if ( $response->needsReauthentication() )
            $this->trigger( 'authorization/denied', $response->getPermissionsDenied() );

        return $response;
    }

    private function get_api_url( $endpoint ): string
    {
        return self::API_URL . $endpoint;
    }

    private function get_authorization_header_value(): string
    {
        return $this->credentials['token_type'] . ' ' . $this->credentials['access_token'];
    }

    private function trigger( $name, $args ): void
    {
        foreach ( $this->listeners as $event_name => $func ) {
            if ( strtolower( $event_name ) == $name ) {
                call_user_func( $func, $args );
            }
        }
    }

    private function maybe_refresh_access_token(): void
    {

        if ( time() > $this->credentials['expiration'] ) {

            // We need a refresh of the access_token
            $auth_client = new AuthClient([
                'client_id' => $this->credentials['client_id'],
                'client_secret' => $this->credentials['client_secret'],
                'redirect_uri' => $this->credentials['redirect_uri']
            ]);

            $resp = $auth_client->refresh_access_token( $this->credentials['refresh_token'] );

            if ( $resp['success'] ) {

                // Merge the new values into the active array
                $this->credentials = array_merge(
                    $this->credentials,
                    [
                        'access_token' => $resp['credentials']['access_token'],
                        'refresh_token' => $resp['credentials']['refresh_token'],
                        'expiration' => time() + (int)$resp['credentials']['expires_in'],
                    ]
                );

                // We need to store the new credentials.
                // Raise an event to let the calling code know.
                $this->trigger( 'access_token/changed', $resp['credentials'] );
            } else {

                // The token refresh failed. We probably need to send user back
                // through the OAuth2 flow again.
                $this->trigger( 'refresh_token/expired', $resp );
            }
        }
    }

    public function addListener( string $event_name, callable $callable ): void
    {
        if ( ! array_key_exists( $event_name, $this->listeners ) )
            $this->listeners[$event_name] = $callable;
    }

    private function logError( $msg ): void {
        if ( true ) {
            error_log( $msg );
        }
    }
}