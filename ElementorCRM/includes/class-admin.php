<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ECRM_Admin {

    public function __construct() {
        add_action( 'admin_menu',           [ $this, 'add_menu' ] );
        add_action( 'admin_init',           [ $this, 'register_settings' ] );
        add_action( 'admin_post_ecrm_set_webhook',    [ $this, 'set_webhook' ] );
        add_action( 'admin_post_ecrm_test_connection', [ $this, 'test_connection' ] );
        add_action( 'admin_post_ecrm_mark_done',      [ $this, 'mark_done' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_ecrm_export_csv', [ $this, 'export_csv' ] );
    }

    public function add_menu() {
        add_menu_page(
            'Elementor CRM',
            'Elementor CRM',
            'manage_options',
            'elementor-crm',
            [ $this, 'render_settings_page' ],
            'dashicons-email-alt',
            30
        );
        
        add_submenu_page(
            'elementor-crm',
            'تنظیمات ربات بله',
            'تنظیمات ربات بله',
            'manage_options',
            'elementor-crm',
            [ $this, 'render_settings_page' ]
        );
    
        add_submenu_page(
            'elementor-crm',
            'ورودی‌های المنتوری',
            'ورودی‌های المنتوری',
            'manage_options',
            'elementor-crm-submissions',
            [ $this, 'render_submissions_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'ecrm_settings', 'ecrm_bot_token',    'sanitize_text_field' );
        register_setting( 'ecrm_settings', 'ecrm_admin_chat_id', 'sanitize_text_field' );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'toplevel_page_elementor-crm' && $hook !== 'elementor-crm_page_elementor-crm-submissions' ) return;
        wp_enqueue_style( 'ecrm-admin', ECRM_URL . 'assets/admin.css', [], ECRM_VERSION );
    }

    public function test_connection() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'ecrm_test_connection' );

        $token = get_option( 'ecrm_bot_token' );

        if ( empty( $token ) ) {
            wp_redirect( admin_url( 'admin.php?page=elementor-crm&test=no_token' ) );
            exit;
        }

        $bot    = new ECRM_BaleBot( $token );
        $result = $bot->test_connection();

        if ( isset( $result['ok'] ) && $result['ok'] === true ) {
            wp_redirect( admin_url( 'admin.php?page=elementor-crm&test=success' ) );
        } else {
            $error = isset( $result['error'] ) ? $result['error'] : ( isset( $result['description'] ) ? $result['description'] : 'خطای ناشناخته' );
            set_transient( 'ecrm_test_error', $error, 60 );
            wp_redirect( admin_url( 'admin.php?page=elementor-crm&test=error' ) );
        }
        exit;
    }

    public function set_webhook() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'ecrm_set_webhook' );

        $token = get_option( 'ecrm_bot_token' );

        if ( empty( $token ) ) {
            wp_redirect( admin_url( 'admin.php?page=elementor-crm&webhook=no_token' ) );
            exit;
        }

        $bot         = new ECRM_BaleBot( $token );
        $webhook_url = rest_url( 'ecrm/v1/bot' );
        $result      = $bot->set_webhook( $webhook_url );

        if ( isset( $result['ok'] ) && $result['ok'] === true ) {
            wp_redirect( admin_url( 'admin.php?page=elementor-crm&webhook=success' ) );
        } else {
            $error = isset( $result['description'] ) ? $result['description'] : 'خطای ناشناخته';
            set_transient( 'ecrm_webhook_error', $error, 60 );
            wp_redirect( admin_url( 'admin.php?page=elementor-crm&webhook=error' ) );
        }
        exit;
    }

    public function mark_done() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'ecrm_mark_done' );

        $submission_id = isset( $_POST['submission_id'] ) ? intval( $_POST['submission_id'] ) : 0;
        $redirect_to_detail = isset( $_POST['redirect_to_detail'] ) ? true : false;
        
        if ( $submission_id > 0 ) {
            ECRM_Database::update_status( $submission_id, 'done' );
        }
        
        if ( $redirect_to_detail ) {
            wp_redirect( admin_url( 'admin.php?page=elementor-crm-submissions&view=' . $submission_id ) );
        } else {
            wp_redirect( admin_url( 'admin.php?page=elementor-crm-submissions&marked=success' ) );
        }
        exit;
    }

    public function render_settings_page() {
        $token       = get_option( 'ecrm_bot_token', '' );
        $chat_id     = get_option( 'ecrm_admin_chat_id', '' );
        $wh_url      = ECRM_Webhook::get_elementor_webhook_url();
        $wh_status   = isset( $_GET['webhook'] ) ? sanitize_text_field( $_GET['webhook'] ) : '';
        $test_status = isset( $_GET['test'] )    ? sanitize_text_field( $_GET['test'] )    : '';
        $saved       = isset( $_GET['settings-updated'] );
        $error_msg   = get_transient( 'ecrm_webhook_error' );
        $test_error  = get_transient( 'ecrm_test_error' );
        ?>
        <div class="wrap ecrm-wrap" dir="rtl">
            <h1>⚙️ تنظیمات Elementor CRM</h1>
    
            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>✅ تنظیمات ذخیره شد.</p></div>
            <?php endif; ?>
    
            <?php if ( $test_status === 'success' ) : ?>
                <div class="notice notice-success is-dismissible"><p>✅ اتصال به API بله موفق بود!</p></div>
            <?php elseif ( $test_status === 'error' ) : ?>
                <div class="notice notice-error is-dismissible">
                    <p>❌ خطا در اتصال: <?php echo $test_error ? esc_html( $test_error ) : 'خطای ناشناخته'; ?></p>
                    <?php delete_transient( 'ecrm_test_error' ); ?>
                </div>
            <?php elseif ( $test_status === 'no_token' ) : ?>
                <div class="notice notice-warning is-dismissible"><p>⚠️ ابتدا توکن را وارد کنید.</p></div>
            <?php endif; ?>
    
            <?php if ( $wh_status === 'success' ) : ?>
                <div class="notice notice-success is-dismissible"><p>✅ وب‌هوک ربات با موفقیت تنظیم شد.</p></div>
            <?php elseif ( $wh_status === 'error' ) : ?>
                <div class="notice notice-error is-dismissible">
                    <p>❌ خطا: <?php echo $error_msg ? esc_html( $error_msg ) : 'خطای ناشناخته'; ?></p>
                    <?php delete_transient( 'ecrm_webhook_error' ); ?>
                </div>
            <?php elseif ( $wh_status === 'no_token' ) : ?>
                <div class="notice notice-warning is-dismissible"><p>⚠️ ابتدا توکن را وارد کنید.</p></div>
            <?php endif; ?>
    
            <div class="ecrm-card">
                <h2>🤖 تنظیمات ربات بله</h2>
                <form method="post" action="options.php">
                    <?php settings_fields( 'ecrm_settings' ); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="ecrm_bot_token">توکن ربات</label></th>
                            <td>
                                <input type="text" id="ecrm_bot_token" name="ecrm_bot_token"
                                    value="<?php echo esc_attr( $token ); ?>"
                                    class="regular-text" placeholder="توکن ربات بله را وارد کنید" />
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ecrm_admin_chat_id">User ID مدیر</label></th>
                            <td>
                                <input type="text" id="ecrm_admin_chat_id" name="ecrm_admin_chat_id"
                                    value="<?php echo esc_attr( $chat_id ); ?>"
                                    class="regular-text" placeholder="شناسه عددی مدیر در بله" />
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( 'ذخیره تنظیمات' ); ?>
                </form>
            </div>
            
            <div class="ecrm-card">
                <h2>📋 راهنمای راه‌اندازی</h2>
                
                <div class="ecrm-steps">
					<h3 style="margin-top:20px;">گام 1: تنظیمات ربات</h3>
                    <p>1️⃣ ربات بله خود را از <a href="https://ble.ir/botfather" target="_blank">BotFather</a> بسازید و توکن را دریافت کنید.</p>
                    <p>2️⃣ شناسه خود را از ربات <a href="https://ble.ir/usersidbot" target="_blank">IDBot</a> دریافت کنید.</p>
                    <p>3️⃣ توکن و شناسه ادمین را در فیلد‌های بالا وارد کرده، سپس دکمه <strong>ذخیره تنظیمات</strong> را بزنید.</p>
                    <p>4️⃣ برای ارسال پیام‌های ربات ، <strong>ابتدا ربات خود را start کنید.</strong> </p>
                </div>
            
                <div class="ecrm-warning">
                    <p><strong>⚠️ جهت دریافت صفحه ورودی و ثبت آن در دیتابیس</strong>، باید متن زیر را در سربرگ یا پاورقی قالب خود قرار دهید:</p>
                    <button type="button" class="button ecrm-copy-code-btn" onclick="ecrmCopyCode()">📋 کپی کد</button>
                </div>
            
                <div class="ecrm-code-box" id="ecrm-js-code">
                    <pre><code>&lt;script&gt;
    jQuery(document).ready(function($) {
        setTimeout(function() {
            try {
                var rawUrl = window.location.href;
                var persianUrl = decodeURI(rawUrl);
                
                var $allFields = $('input[id="form-field-my_page_url"]');
                
                $allFields.each(function() {
                    $(this).val(persianUrl); 
                    $(this).trigger('change');
                });
                
            } catch (e) {
                console.log('Error in URL injection');
            }
        }, 1500);
    });
    &lt;/script&gt;</code></pre>
                </div>
    
                <h3 style="margin-top:20px;">گام 2: آدرس وب‌هوک المنتور</h3>
                <p>1️⃣ از تنظیمات فرم ، بخش واکنش بعد از ارسال ، <strong>افزودن واکنش را زده و وب‌هوک</strong> رو اضافه کنید .</p>
                <p>2️⃣ پس از فعال سازی واکنش وب‌هوک، بخش تنظیمات وب‌هوک برای شما ایجاد می‌شود . به تنظیمات وب‌هوک رفته و <strong>وب‌هوک زیر رو در آن بخش</strong> وارد نمایید .</p>
                <div class="ecrm-webhook-box">
                    <input type="text" id="ecrm-wh-url" value="<?php echo esc_attr( $wh_url ); ?>" readonly class="regular-text" />
                    <button type="button" class="button" onclick="ecrmCopy()">📋 کپی</button>
                </div>
                <p>3️⃣ همچنین در همان قسمت تنظیمات وب‌هوک ، <strong>گزینه اطلاعات پیشرفته رو فعال</strong> و تیک آن را روشن نمایید.</p>
    
                <h3 style="margin-top:20px;">گام 3: فعال‌سازی ربات</h3>
                <p>جهت هماهنگ‌سازی ربات با وب‌سایت خود ، می‌بایست توسط دکمه زیر <strong>درخواست وب‌هوک را برای پیام‌رسان بله</strong> ارسال نمایید .</p>
                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                    <?php wp_nonce_field( 'ecrm_set_webhook' ); ?>
                    <input type="hidden" name="action" value="ecrm_set_webhook" />
                    <button type="submit" class="button button-primary">🚀 تنظیم وب‌هوک و فعال‌سازی ربات</button>
                </form>
            
                <div class="ecrm-success">
                    <p><strong>✅ تمام ! فرم‌های المنتوری شما به صورت خودکار به ربات بله ارسال می‌شوند.</strong></p>
                </div>
				
            </div>
    
            <div class="ecrm-card">
                <h2>🔍 تست و راه‌اندازی</h2>
    
                <h3>تست اتصال ربات با وبسایت شما</h3>
                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                    <?php wp_nonce_field( 'ecrm_test_connection' ); ?>
                    <input type="hidden" name="action" value="ecrm_test_connection" />
                    <button type="submit" class="button">🔍 تست اتصال به API بله</button>
                </form>
            </div>
        </div>
    
        <script>
        function ecrmCopy() {
            var input = document.getElementById('ecrm-wh-url');
            input.select();
            document.execCommand('copy');
            alert('✅ آدرس کپی شد!');
        }
    
        function ecrmCopyCode() {
            var code = document.getElementById('ecrm-js-code').querySelector('code').innerText;
            var textarea = document.createElement('textarea');
            textarea.value = code;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            
            var btn = document.querySelector('.ecrm-copy-code-btn');
            var originalText = btn.innerText;
            btn.innerText = '✅ کپی شد!';
            btn.style.background = '#28a745';
            btn.style.color = '#fff';
            
            setTimeout(function() {
                btn.innerText = originalText;
                btn.style.background = '';
                btn.style.color = '';
            }, 2000);
        }
        </script>
        <?php
    }

    public function render_submissions_page() {
        
        if ( isset( $_GET['view'] ) && is_numeric( $_GET['view'] ) ) {
            $this->render_submission_detail( intval( $_GET['view'] ) );
            return;
        }
        
        $marked = isset( $_GET['marked'] ) ? sanitize_text_field( $_GET['marked'] ) : '';
        $submissions = ECRM_Database::get_recent( 50 );
        ?>
		
		<div class="wrap ecrm-wrap" dir="rtl" style="margin-bottom: 0;">
			<div class="ecrm-card">
				<div style="display: grid; grid-template-columns: 60% 40%; align-items: center; gap: 20px;">
					<div>
						<strong>📢 مشارکت و توسعه افزونه</strong>
						<p style="margin: 4px 0 0; color: #666; font-size: 13px;">
							جهت برخورداری از آخرین اخبار مربوط به افزونه ElementorCRM و ثبت هرگونه فید بک ( پیشنهاد و انتقاد ) میتوانید از طریق آیدی‌های نام‌برده با ما ارتباط برقرار نمایید ... مشتاق دیدار 🌹
						</p>
					</div>
					<div style="display: flex; flex-direction: row; justify-content: center; align-items: center; gap: 10px;">
						<a href="https://t.me/WpGit" target="_blank" class="button button-primary" style="text-align: center;">
							📣 کانال پیام‌رسان تلگرام
						</a>
						<a href="https://ble.ir/wordpress_fa" target="_blank" class="button" style="text-align: center;">
							💬 کانال پیام‌رسان بله
						</a>
					</div>
				</div>
			</div>
		</div>
		
        <div class="wrap ecrm-wrap" dir="rtl">
            <h1>📊 فرم‌های دریافتی</h1>

            <?php if ( $marked === 'success' ) : ?>
                <div class="notice notice-success is-dismissible"><p>✅ وضعیت به‌روزرسانی شد.</p></div>
            <?php endif; ?>

            <?php if ( empty( $submissions ) ) : ?>
                <div class="ecrm-card">
                    <p>هنوز فرمی دریافت نشده است.</p>
                </div>
            <?php else : ?>
                <div class="ecrm-card">
                    <div style="margin-bottom: 15px; text-align: left;">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
                            <?php wp_nonce_field( 'ecrm_export_csv', 'ecrm_csv_nonce' ); ?>
                            <input type="hidden" name="action" value="ecrm_export_csv">
                            <button type="submit" class="button button-primary">
                                📥 دریافت خروجی اکسل
                            </button>
                        </form>
                    </div>

                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 10px;">#</th>
                                <th style="width: 80px;">نام فرم</th>
                                <th style="width: 120px;">تاریخ ارسال</th>
                                <th style="width: 120px;">وضعیت</th>
                                <th style="width: 80px;">مشاهده</th>
                                <th style="width: 80px;">عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $submissions as $sub ) :
                                $jalali = ECRM_Webhook::to_jalali( strtotime( $sub->submitted_at ) );
                                $is_done = ( $sub->status === 'done' );
                            ?>
                            <tr>
                                <td><?php echo esc_html( $sub->id ); ?></td>
                                <td><?php echo esc_html( $sub->form_name ); ?></td>
                                <td><?php echo esc_html( $jalali ); ?></td>
                                <td>
                                    <?php if ( $is_done ) : ?>
                                        <span style="color:green;">✅ پیگیری شده</span>
                                    <?php else : ?>
                                        <span style="color:red;">🔴 رسیدگی نشده</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url( 'admin.php?page=elementor-crm-submissions&view=' . $sub->id ); ?>"
                                       class="button button-small" 
                                       title="مشاهده جزئیات کامل فرم">
                                        📄 مشاهده جزئیات
                                    </a>
                                </td>
                                <td>
                                    <?php if ( ! $is_done ) : ?>
                                        <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline;">
                                            <?php wp_nonce_field( 'ecrm_mark_done' ); ?>
                                            <input type="hidden" name="action" value="ecrm_mark_done" />
                                            <input type="hidden" name="submission_id" value="<?php echo esc_attr( $sub->id ); ?>" />
                                            <button type="submit" class="button button-small">✓ پیگیری شد</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function export_csv() {
        if ( ! isset( $_POST['ecrm_csv_nonce'] ) || ! wp_verify_nonce( $_POST['ecrm_csv_nonce'], 'ecrm_export_csv' ) ) {
            wp_die( 'دسترسی غیرمجاز' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'شما دسترسی لازم را ندارید' );
        }

        global $wpdb;
        $table = ECRM_Database::get_table_name();
        $submissions = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY submitted_at ASC" );

        if ( empty( $submissions ) ) {
            wp_die( 'هیچ داده‌ای برای خروجی وجود ندارد' );
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

        header( 'Content-Type: text/csv; charset=UTF-8' );
        $domain = parse_url( home_url(), PHP_URL_HOST );
        $domain = str_replace( 'www.', '', $domain );
        header( 'Content-Disposition: attachment; filename="eCRM - ' . $domain . '.csv"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );
        fprintf( $output, chr(0xEF).chr(0xBB).chr(0xBF) );

        fputcsv( $output, $header );

        foreach ( $submissions as $sub ) {
            $fields_decoded = json_decode( $sub->fields, true );
            $status = ( $sub->status === 'done' ) ? 'پیگیری شده' : 'رسیدگی نشده';
            $date = ECRM_Webhook::to_jalali( strtotime( $sub->submitted_at ) );

            $row = [
                $sub->id,
                $date,
                $status
            ];

            foreach ( $all_field_labels as $label ) {
                if ( isset( $fields_decoded[ $label ] ) ) {
                    $value = $fields_decoded[ $label ];
                    
                    if ( is_array( $value ) ) {
                        $row[] = implode( ' | ', $value );
                    } else {
                        $row[] = $value;
                    }
                } else {
                    $row[] = ''; 
                }
            }

            fputcsv( $output, $row );
        }

        fclose( $output );
        exit;
    }
    
    public function render_submission_detail( $submission_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ecrm_submissions';
    
        $submission = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $submission_id ) );
    
        if ( ! $submission ) {
            wp_die( 'فرم مورد نظر یافت نشد.' );
        }
    
        $fields = json_decode( $submission->fields, true );
        $jalali = ECRM_Webhook::to_jalali( strtotime( $submission->submitted_at ) );
        $is_done = ( $submission->status === 'done' );
        ?>
        <div class="wrap ecrm-wrap">
            <h1>📄 جزئیات فرم شماره <?php echo esc_html( $submission->id ); ?></h1>
    
            <p>
                <a href="<?php echo admin_url( 'admin.php?page=elementor-crm-submissions' ); ?>" class="button">
                    ← بازگشت به لیست فرم‌ها
                </a>
            </p>
    
            <div class="ecrm-detail-box">
                <h2>اطلاعات کلی</h2>
                <table class="form-table">
                    <tr>
                        <th>شماره فرم:</th>
                        <td><strong><?php echo esc_html( $submission->id ); ?></strong></td>
                    </tr>
                    <tr>
                        <th>نام فرم:</th>
                        <td><?php echo esc_html( $submission->form_name ); ?></td>
                    </tr>
                    <tr>
                        <th>تاریخ ارسال:</th>
                        <td><?php echo esc_html( $jalali ); ?></td>
                    </tr>
                    <tr>
                        <th>وضعیت:</th>
                        <td>
                            <?php if ( $is_done ) : ?>
                                <span style="color:green; font-weight:bold;">✅ پیگیری شده</span>
                            <?php else : ?>
                                <span style="color:red; font-weight:bold;">🔴 رسیدگی نشده</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ( ! empty( $submission->page_url ) ) : ?>
                    <tr>
                        <th>لینک صفحه ارسال:</th>
                        <td>
                            <a href="<?php echo esc_url( $submission->page_url ); ?>" target="_blank">
                                <?php echo esc_html( $submission->page_url ); ?>
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
    
            <div class="ecrm-detail-box">
                <h2>فیلدهای فرم</h2>
                <?php if ( ! empty( $fields ) && is_array( $fields ) ) : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 30%;">نام فیلد</th>
                                <th>مقدار</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $fields as $label => $value ) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $label ); ?></strong></td>
                                    <td><?php echo nl2br( esc_html( $value ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p>هیچ فیلدی برای نمایش وجود ندارد.</p>
                <?php endif; ?>
            </div>
    
            <?php if ( ! $is_done ) : ?>
                <div class="ecrm-detail-box">
                    <h2>عملیات</h2>
                    <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                        <?php wp_nonce_field( 'ecrm_mark_done' ); ?>
                        <input type="hidden" name="action" value="ecrm_mark_done" />
                        <input type="hidden" name="submission_id" value="<?php echo esc_attr( $submission->id ); ?>" />
                        <input type="hidden" name="redirect_to_detail" value="1" />
                        <button type="submit" class="button button-primary button-large">
                            ✓ علامت‌گذاری به عنوان پیگیری شده
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
