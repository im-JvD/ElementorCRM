// add to footer.php Theme File or WPCode Snippets Plugin

<script>
jQuery(document).ready(function($) {
    setTimeout(function() {
        try {
            // 1. دریافت و فارسی‌سازی آدرس صفحه
            var rawUrl = window.location.href;
            var persianUrl = decodeURI(rawUrl);
            
            // 2. پیدا کردن تمام فیلدها در تمام فرم‌ها
            var $allFields = $('input[id="form-field-my_page_url"]');
            
            // 3. پر کردن تک تک فیلدها با یک حلقه
            $allFields.each(function() {
                $(this).val(persianUrl); 
                $(this).trigger('change');
            });
            
        } catch (e) {
            console.log('Error in URL injection');
        }
    }, 1500);
});
</script>
