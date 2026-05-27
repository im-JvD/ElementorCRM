<?php
/**
Plugin Name:        ElementorCRM
Plugin URI:         https://WpGit.ir/landing/ElementorCRM
Description:        ذخیره فرم‌های المنتوری و ارسال آن در پیام‌رسان بله توسط وب‌هوک با قابلیت خروجی داده‌ها بصورت فایل csv .
Version:            1.0.1
Author:             محمد جواد کریمی
Author URI:         https://mohamadjavadkarimi.ir/
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ECRM_VERSION', '1.0.0' );
define( 'ECRM_PATH',    plugin_dir_path( __FILE__ ) );
define( 'ECRM_URL',     plugin_dir_url( __FILE__ ) );

require_once ECRM_PATH . 'includes/class-database.php';
require_once ECRM_PATH . 'includes/class-bale-bot.php';
require_once ECRM_PATH . 'includes/class-webhook.php';
require_once ECRM_PATH . 'includes/class-admin.php';

register_activation_hook( __FILE__, function() {
    ECRM_Database::create_table();
} );

add_action( 'plugins_loaded', function() {
    new ECRM_Admin();
    new ECRM_Webhook();
} );
