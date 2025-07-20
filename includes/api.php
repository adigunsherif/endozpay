<?php

/**
 * EndozPay API functions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


function endozpay_api_login( $base_url, $client_id, $secret_key, $public_key ) {
    $response = wp_remote_post( "$base_url/login/", array(
        'headers' => [
          'Content-Type' => 'application/json',
          "X-CLIENTID" => $client_id
        ],
        'body'    => json_encode([
            'secret_key' => $secret_key,
            'public_key' => $public_key
        ])
    ));

    if ( is_wp_error( $response ) ) {
        error_log('[EndozPay Login Error] ' . $response->get_error_message());
        return false;
    }
    $body = json_decode( $response['body'], true );
    return $body['access'] ?? false;
}

function endozpay_api_initiate_payment( $base_url, $token, $client_id, $payload ) {
    $response = wp_remote_post( "$base_url/initiate-payment/", array(
        'headers' => [
            'Authorization' => "Bearer $token",
            'Content-Type'  => 'application/json',
            "X-CLIENTID" => $client_id
        ],
        'body' => json_encode( $payload )
    ));

    if ( is_wp_error( $response ) ) {
        error_log('[EndozPay Payment Error] ' . $response->get_error_message());
        return false;
    }

    $body = json_decode( $response['body'], true );
    return $body;
}

