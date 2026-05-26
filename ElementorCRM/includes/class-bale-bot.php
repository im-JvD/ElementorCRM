<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ECRM_BaleBot {

    private $token;
    private $api_url;

    public function __construct( $token ) {
        $this->token   = $token;
        $this->api_url = 'https://tapi.bale.ai/bot' . $token . '/';
    }

    private function request( $method, $params = [], $http_method = 'POST' ) {
        $args = [
            'timeout'   => 30,
            'sslverify' => true,
            'headers'   => [ 'Content-Type' => 'application/json' ],
        ];

        if ( $http_method === 'GET' ) {
            $response = wp_remote_get( $this->api_url . $method, $args );
        } else {
            $args['body'] = wp_json_encode( $params, JSON_UNESCAPED_UNICODE );
            $response     = wp_remote_post( $this->api_url . $method, $args );
        }

        if ( is_wp_error( $response ) ) {
            error_log( '[ECRM] API WP_Error (' . $method . '): ' . $response->get_error_message() );
            return [ 'ok' => false, 'error' => $response->get_error_message() ];
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        return $data;
    }

    public function test_connection() {
        return $this->request( 'getMe', [], 'GET' );
    }

    public function set_webhook( $url ) {
        return $this->request( 'setWebhook', [ 'url' => $url ] );
    }

    public function send_message( $chat_id, $text, $reply_markup = null ) {
        $params = [
            'chat_id'    => $chat_id,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];

        if ( $reply_markup ) {
            $params['reply_markup'] = $reply_markup;
        }

        return $this->request( 'sendMessage', $params );
    }

    public function answer_callback_query( $callback_query_id, $text = '' ) {
        return $this->request( 'answerCallbackQuery', [
            'callback_query_id' => $callback_query_id,
            'text'              => $text,
        ] );
    }

    public function edit_message_reply_markup( $chat_id, $message_id, $reply_markup ) {
        return $this->request( 'editMessageReplyMarkup', [
            'chat_id'      => $chat_id,
            'message_id'   => $message_id,
            'reply_markup' => $reply_markup,
        ] );
    }
    
    public function edit_message_text( $chat_id, $message_id, $text, $reply_markup = null ) {
        $url  = $this->api_url . 'editMessageText';
        $data = [
            'chat_id'    => $chat_id,
            'message_id' => $message_id,
            'text'       => $text,
        ];
    
        if ( $reply_markup ) {
            $data['reply_markup'] = json_encode( $reply_markup );
        }
    
        return $this->request( $url, $data );
    }
    
    public function send_main_menu( $chat_id ) {
        $text = "👋 *خوش آمدید*\n\nلطفاً یکی از گزینه‌های زیر را انتخاب کنید:";
        
        $reply_markup = [
            'keyboard' => [
                [
                    ['text' => '📊 وضعیت لیدها']
                ],
                [
                    ['text' => '📥 دریافت خروجی اکسل']
                ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];
        
        return $this->send_message( $chat_id, $text, $reply_markup );
    }

    public function send_document( $chat_id, $file_path, $caption = '' ) {
        $boundary = wp_generate_password( 24, false );
        $file_name = basename( $file_path );
        $file_contents = file_get_contents( $file_path );

        if ( $file_contents === false ) {
            error_log( '[ECRM] Cannot read file: ' . $file_path );
            return [ 'ok' => false, 'error' => 'File read error' ];
        }

        $body = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"chat_id\"\r\n\r\n{$chat_id}\r\n";
        
        if ( $caption ) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"caption\"\r\n\r\n{$caption}\r\n";
        }
        
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"document\"; filename=\"{$file_name}\"\r\n";
        $body .= "Content-Type: text/csv\r\n\r\n";
        $body .= $file_contents . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $args = [
            'timeout'   => 30,
            'sslverify' => true,
            'headers'   => [
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $body,
        ];

        $response = wp_remote_post( $this->api_url . 'sendDocument', $args );

        if ( is_wp_error( $response ) ) {
            error_log( '[ECRM] sendDocument WP_Error: ' . $response->get_error_message() );
            return [ 'ok' => false, 'error' => $response->get_error_message() ];
        }

        $body_response = wp_remote_retrieve_body( $response );
        return json_decode( $body_response, true );
    }
}
