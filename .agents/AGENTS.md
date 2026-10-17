# WordPress & WooCommerce Development Rules

Always strictly follow WordPress Coding Standards (WPCS) when creating or modifying WordPress and WooCommerce plugins:

1. **Input Handling & Unslashing**:
   - Always unslash superglobals (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`) with `wp_unslash()` before sanitizing (e.g. `sanitize_text_field(wp_unslash($_POST['var']))`).
   - Validate and sanitize all user input before processing.

2. **Output Escaping & Security**:
   - Escape all dynamic data before outputting in HTML (`esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`, `esc_js()`).
   - Verify nonces (`check_ajax_referer()`, `wp_verify_nonce()`) and user capabilities (`current_user_can()`) for all admin and AJAX actions.

3. **WordPress Trademark & Naming Rules**:
   - Ensure plugin names and slugs do not start with trademarked terms (e.g. use "... for FB", not "FB ...").
   - Prefix all custom functions, options, constants, classes, and hooks to prevent global scope collisions.

4. **Date & Time Formatting**:
   - Never use `date()` as it is affected by runtime timezone mutations. Always use `gmdate()` or `wp_date()`.
