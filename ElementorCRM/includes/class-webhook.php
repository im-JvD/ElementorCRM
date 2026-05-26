<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ECRM_Webhook {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public static function get_elementor_webhook_url() {
        return rest_url( 'ecrm/v1/form' );
    }

    public function register_routes() {
        register_rest_route( 'ecrm/v1', '/form', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_form' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'ecrm/v1', '/bot', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_bot' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function handle_form( WP_REST_Request $request ) {
        $token   = get_option( 'ecrm_bot_token' );
        $chat_id = get_option( 'ecrm_admin_chat_id' );

        if ( empty( $token ) || empty( $chat_id ) ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'Bot not configured' ], 400 );
        }

        $body = $request->get_body();

        $data = json_decode( $body, true );
        if ( ! $data ) {
            $data = $request->get_params();
        }

        $form_name = '';
        $page_url  = '';

        if ( isset( $data['form_name'] ) ) {
            $form_name = sanitize_text_field( $data['form_name'] );
        } elseif ( isset( $data['id'] ) ) {
            $form_name = 'فرم ' . sanitize_text_field( $data['id'] );
        } else {
            $form_name = 'فرم المنتور';
        }

        if ( isset( $data['page_url'] ) ) {
            $page_url = esc_url_raw( $data['page_url'] );
        } elseif ( isset( $data['referrer'] ) ) {
            $page_url = esc_url_raw( $data['referrer'] );
        }

        $fields = [];

        if ( isset( $data['fields'] ) && is_array( $data['fields'] ) ) {
            foreach ( $data['fields'] as $field ) {
                if ( isset( $field['id'] ) && isset( $field['value'] ) ) {
                    $label          = isset( $field['title'] ) ? $field['title'] : $field['id'];
                    $fields[ $label ] = $field['value'];
                }
            }
        } else {
            $skip_keys = [ 'form_name', 'form_id', 'id', 'referer', 'referrer', 'page_url', 'post_id' ];
            foreach ( $data as $key => $value ) {
                if ( ! in_array( $key, $skip_keys ) && ! is_array( $value ) ) {
                    $fields[ $key ] = $value;
                }
            }
        }

        $jalali_date = self::to_jalali( current_time( 'timestamp' ) );

        $submission_id = ECRM_Database::insert_submission( [
            'form_name' => $form_name,
            'page_url'  => $page_url,
            'fields'    => $fields,
        ] );

        if ( ! $submission_id ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'DB error' ], 500 );
        }

        $text  = "📋 فرم جدید دریافت شد\n";
        $text .= "📅 *تاریخ:* " . $jalali_date . "\n\n";
        if ( $page_url ) {
            $text .= "🔗 *صفحه:* " . $page_url . "\n";
        }
        
        foreach ( $fields as $label => $value ) {
            $text .= "▫️ " . $label . ": " . $value . "\n";
        }
        
        $text .= "\n🆔 *شناسه:* #" . $submission_id;

        $reply_markup = [
            'inline_keyboard' => [
                [
                    [
                        'text'          => '🔴 رسیدگی نشده',
                        'callback_data' => 'status_done_' . $submission_id,
                    ]
                ]
            ]
        ];

        $bot    = new ECRM_BaleBot( $token );
        $result = $bot->send_message( $chat_id, $text, $reply_markup );

        if ( isset( $result['ok'] ) && $result['ok'] && isset( $result['result']['message_id'] ) ) {
            ECRM_Database::update_message_id(
                $submission_id,
                $result['result']['message_id'],
                $chat_id
            );
        }

        return new WP_REST_Response( [ 'ok' => true ], 200 );
    }

    public function handle_bot( WP_REST_Request $request ) {
        $body   = $request->get_body();
        $update = json_decode( $body, true );

        $token   = get_option( 'ecrm_bot_token' );
        $admin_chat_id = get_option( 'ecrm_admin_chat_id' );

        if ( empty( $token ) ) {
            return new WP_REST_Response( [ 'ok' => true ], 200 );
        }

        $bot = new ECRM_BaleBot( $token );

        if ( isset( $update['message']['text'] ) ) {
            $chat_id = $update['message']['chat']['id'];
            $text    = trim( $update['message']['text'] );

            if ( $admin_chat_id && (string) $chat_id !== (string) $admin_chat_id ) {
                $bot->send_message( $chat_id, '⛔️ شما مجاز به استفاده از این ربات نیستید.' );
                return new WP_REST_Response( [ 'ok' => true ], 200 );
            }

            if ( $text === '/start' ) {
                $bot->send_main_menu( $chat_id );
                return new WP_REST_Response( [ 'ok' => true ], 200 );
            }

            if ( $text === '📥 دریافت خروجی اکسل' ) {
                $this->handle_export_csv( $chat_id, $bot );
                return new WP_REST_Response( [ 'ok' => true ], 200 );
            }
            
            if ( $text === '📊 وضعیت لیدها' ) {
                $pending_count = ECRM_Database::count_by_status( 'pending' );
                $done_count    = ECRM_Database::count_by_status( 'done' );
                
                $site_url = get_site_url();
                
                $status_text = "📊 *خلاصه وضعیت لیدها*\n";
                $status_text .= "🌐 *وب‌سایت:* " . $site_url . "\n\n";
                $status_text .= "✅ پیگیری شده: *{$done_count}* لید\n";
                $status_text .= "🔴 نیاز به بررسی: *{$pending_count}* لید\n\n";
                $status_text .= "برای مشاهده جزئیات، یکی از گزینه‌های زیر را انتخاب کنید:";
                
                $status_markup = [
                    'inline_keyboard' => [
                        [
                            [
                                'text'          => '✅ پیگیری شده‌ها',
                                'callback_data' => 'show_done',
                            ]
                        ],
                        [
                            [
                                'text'          => '🔴 پیگیری نشده‌ها',
                                'callback_data' => 'show_pending',
                            ]
                        ]
                    ]
                ];
                
                $bot->send_message( $chat_id, $status_text, $status_markup );
                return new WP_REST_Response( [ 'ok' => true ], 200 );
            }

        }

        if ( isset( $update['callback_query'] ) ) {
            $callback       = $update['callback_query'];
            $callback_id    = $callback['id'];
            $callback_data  = isset( $callback['data'] ) ? $callback['data'] : '';
            $message_id     = isset( $callback['message']['message_id'] ) ? $callback['message']['message_id'] : 0;
            $chat_id        = isset( $callback['message']['chat']['id'] ) ? $callback['message']['chat']['id'] : 0;

            if ( strpos( $callback_data, 'status_done_' ) === 0 ) {
                $submission_id = (int) str_replace( 'status_done_', '', $callback_data );

                ECRM_Database::update_status( $submission_id, 'done' );

                $new_markup = [
                    'inline_keyboard' => [
                        [
                            [
                                'text'          => '✅ پیگیری شده',
                                'callback_data' => 'status_pending_' . $submission_id,
                            ]
                        ]
                    ]
                ];

                $bot->edit_message_reply_markup( $chat_id, $message_id, $new_markup );
                $bot->answer_callback_query( $callback_id, '✅ وضعیت به پیگیری شده تغییر یافت' );

            } elseif ( strpos( $callback_data, 'status_pending_' ) === 0 ) {
                $submission_id = (int) str_replace( 'status_pending_', '', $callback_data );

                ECRM_Database::update_status( $submission_id, 'pending' );

                $new_markup = [
                    'inline_keyboard' => [
                        [
                            [
                                'text'          => '🔴 رسیدگی نشده',
                                'callback_data' => 'status_done_' . $submission_id,
                            ]
                        ]
                    ]
                ];

                $bot->edit_message_reply_markup( $chat_id, $message_id, $new_markup );
                $bot->answer_callback_query( $callback_id, '🔴 وضعیت به رسیدگی نشده تغییر یافت' );
            
            } elseif ( $callback_data === 'show_pending' ) {
                $leads = ECRM_Database::get_by_status( 'pending', 50 );
                
                if ( empty( $leads ) ) {
                    $bot->answer_callback_query( $callback_id, '✅ هیچ لید پیگیری نشده‌ای وجود ندارد', true );
                    return new WP_REST_Response( [ 'ok' => true ], 200 );
                }
                
                $bot->answer_callback_query( $callback_id, '🔄 در حال ارسال...' );
                
                foreach ( $leads as $lead ) {
                    $fields = json_decode( $lead->fields, true );
                    $jalali_date = self::to_jalali( strtotime( $lead->submitted_at ) );
                    
                    $text  = "📋 فرم جدید دریافت شد\n";
                    $text .= "📅 *تاریخ:* " . $jalali_date . "\n\n";
                    if ( $lead->page_url ) {
                        $text .= "🔗 *صفحه:* " . $lead->page_url . "\n";
                    }
                    
                    if ( is_array( $fields ) ) {
                        foreach ( $fields as $label => $value ) {
                            $text .= "▫️ " . $label . ": " . $value . "\n";
                        }
                    }
                    
                    $text .= "\n🆔 *شناسه:* #" . $lead->id;
                    
                    $lead_markup = [
                        'inline_keyboard' => [
                            [
                                [
                                    'text'          => '🔴 رسیدگی نشده',
                                    'callback_data' => 'status_done_' . $lead->id,
                                ]
                            ]
                        ]
                    ];
                    
                    $bot->send_message( $chat_id, $text, $lead_markup );
                    usleep( 300000 );
                }
            
            } elseif ( $callback_data === 'show_done' ) {
                $leads = ECRM_Database::get_by_status( 'done', 50 );
                
                if ( empty( $leads ) ) {
                    $bot->answer_callback_query( $callback_id, '❌ هیچ لید پیگیری شده‌ای وجود ندارد', true );
                    return new WP_REST_Response( [ 'ok' => true ], 200 );
                }
                
                $bot->answer_callback_query( $callback_id, '🔄 در حال ارسال...' );
                
                foreach ( $leads as $lead ) {
                    $fields = json_decode( $lead->fields, true );
                    $jalali_date = self::to_jalali( strtotime( $lead->submitted_at ) );
                    
                    $text  = "📋 فرم جدید دریافت شد\n";
                    $text .= "📅 *تاریخ:* " . $jalali_date . "\n\n";
                    if ( $lead->page_url ) {
                        $text .= "🔗 *صفحه:* " . $lead->page_url . "\n";
                    }
                    
                    if ( is_array( $fields ) ) {
                        foreach ( $fields as $label => $value ) {
                            $text .= "▫️ " . $label . ": " . $value . "\n";
                        }
                    }
                    
                    $text .= "\n🆔 *شناسه:* #" . $lead->id;
                    
                    $lead_markup = [
                        'inline_keyboard' => [
                            [
                                [
                                    'text'          => '✅ پیگیری شده',
                                    'callback_data' => 'status_pending_' . $lead->id,
                                ]
                            ]
                        ]
                    ];
                    
                    $bot->send_message( $chat_id, $text, $lead_markup );
                    usleep( 300000 );
                }
            
            } else {
                $bot->answer_callback_query( $callback_id );
            }
        }

        return new WP_REST_Response( [ 'ok' => true ], 200 );
    }

    private function handle_export_csv( $chat_id, $bot ) {
        global $wpdb;
        $table = ECRM_Database::get_table_name();
        $submissions = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY submitted_at ASC" );
    
        if ( empty( $submissions ) ) {
            $bot->send_message( $chat_id, '❌ هیچ داده‌ای برای خروجی وجود ندارد.' );
            return;
        }
    
        $all_field_labels = [];
        foreach ( $submissions as $sub ) {
            $fields_decoded = json_decode( $sub->fields, true );
            if ( is_array( $fields_decoded ) ) {
                foreach ( array_keys( $fields_decoded ) as $label ) {
                    if ( ! in_array( $label, $all_field_labels, true ) ) {
                        $all_field_labels[] = $label;
                    }
                }
            }
        }
    
        $header = array_merge(
            [ 'شماره', 'تاریخ ارسال', 'وضعیت' ],
            $all_field_labels
        );
    
        $domain = parse_url( home_url(), PHP_URL_HOST );
        $domain = str_replace( 'www.', '', $domain );
        $domain = sanitize_file_name( $domain );
    
        $upload_dir = wp_upload_dir();
    
        // ✅ چک کردن خطای upload_dir
        if ( ! empty( $upload_dir['error'] ) ) {
            error_log( '[ECRM] Upload dir error: ' . $upload_dir['error'] );
            $bot->send_message( $chat_id, '❌ خطا در دسترسی به پوشه آپلود.' );
            return;
        }
    
        $csv_file = $upload_dir['basedir'] . '/eCRM - ' . $domain . '.csv';
    
        $output = fopen( $csv_file, 'w' );
    
        // ✅ چک کردن موفقیت fopen
        if ( $output === false ) {
            error_log( '[ECRM] Cannot open file for writing: ' . $csv_file );
            $bot->send_message( $chat_id, '❌ خطا در ساخت فایل CSV.' );
            return;
        }
    
        fprintf( $output, chr(0xEF).chr(0xBB).chr(0xBF) );
        fputcsv( $output, $header );
    
        foreach ( $submissions as $sub ) {
            $fields_decoded = json_decode( $sub->fields, true );
            $status = ( $sub->status === 'done' ) ? 'پیگیری شده' : 'رسیدگی نشده';
            $date = self::to_jalali( strtotime( $sub->submitted_at ) );
    
            $row = [ $sub->id, $date, $status ];
    
            foreach ( $all_field_labels as $label ) {
                if ( isset( $fields_decoded[ $label ] ) ) {
                    $value = $fields_decoded[ $label ];
                    $row[] = is_array( $value ) ? implode( ' | ', $value ) : $value;
                } else {
                    $row[] = '';
                }
            }
    
            fputcsv( $output, $row );
        }
    
        fclose( $output );
    
        // ✅ چک کردن اینکه فایل واقعاً ساخته شده
        if ( ! file_exists( $csv_file ) || filesize( $csv_file ) === 0 ) {
            error_log( '[ECRM] CSV file missing or empty: ' . $csv_file );
            $bot->send_message( $chat_id, '❌ خطا در ساخت فایل CSV.' );
            return;
        }
    
        $result = $bot->send_document( $chat_id, $csv_file, '✅ فایل خروجی فرم‌های دریافتی' );
    
        if ( file_exists( $csv_file ) ) {
            unlink( $csv_file );
        }
    
        if ( ! isset( $result['ok'] ) || ! $result['ok'] ) {
            $error_desc = isset( $result['description'] ) ? $result['description'] : 'خطای ناشناخته';
            error_log( '[ECRM] sendDocument failed: ' . $error_desc );
            $bot->send_message( $chat_id, '❌ خطا در ارسال فایل: ' . $error_desc );
        }
    }
    
    public static function to_jalali( $timestamp ) {
    
        $hour   = gmdate( 'H', $timestamp );
        $minute = gmdate( 'i', $timestamp );
        $gy     = (int) gmdate( 'Y', $timestamp );
        $gm     = (int) gmdate( 'n', $timestamp );
        $gd     = (int) gmdate( 'j', $timestamp );
    
        list( $jy, $jm, $jd ) = self::gregorian_to_jalali( $gy, $gm, $gd );
    
        return sprintf('%d/%02d/%02d - %s:%s', $jy, $jm, $jd, $hour, $minute);
    }
    
    private static function gregorian_to_jalali( $gy, $gm, $gd ) {
    
        $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $j_days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
    
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    
        $days = 355666
            + (365 * $gy)
            + (int)(($gy2 + 3) / 4)
            - (int)(($gy2 + 99) / 100)
            + (int)(($gy2 + 399) / 400)
            + $gd;
    
        for ($i = 0; $i < $gm - 1; $i++) {
            $days += $g_days_in_month[$i];
        }
    
        $jy = -1595 + (33 * (int)($days / 12053));
        $days %= 12053;
    
        $jy += 4 * (int)($days / 1461);
        $days %= 1461;
    
        if ($days > 365) {
            $jy  += (int)(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
    
        if ($days < 186) {
            $jm = 1 + (int)($days / 31);
            $jd = 1 + ($days % 31);
        } else {
            $days -= 186;
            $jm   = 7 + (int)($days / 30);
            $jd   = 1 + ($days % 30);
        }
    
        return [$jy, $jm, $jd];
    }

}
