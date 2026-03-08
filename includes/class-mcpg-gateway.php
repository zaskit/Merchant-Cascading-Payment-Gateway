<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MCPG_Gateway extends WC_Payment_Gateway {

    private $logger;

    public function __construct() {
        $this->id                 = 'mcpg_cascading';
        $this->method_title       = 'Cascading Payment Gateway';
        $this->method_description = 'Cascading payment orchestration — automatically routes transactions through multiple processors for maximum approval rates.';
        $this->has_fields         = true;
        $this->supports           = array( 'products', 'refunds' );

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );
        $this->debug       = 'yes' === $this->get_option( 'debug' );

        $this->logger = new MCPG_Logger( $this->debug, 'mcpg-gateway' );

        // Icons
        $this->icon = '';

        // Save settings
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

        // Checkout assets
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ) );

        // Cascade overlay on thank-you page
        add_action( 'woocommerce_before_thankyou', array( $this, 'render_cascade_overlay' ), 1 );

        // AJAX handlers
        add_action( 'wp_ajax_mcpg_cascade_process', array( $this, 'ajax_cascade_process' ) );
        add_action( 'wp_ajax_nopriv_mcpg_cascade_process', array( $this, 'ajax_cascade_process' ) );

        // Block checkout bridge
        add_action( 'woocommerce_rest_checkout_process_payment_with_context', array( $this, 'process_payment_for_block' ), 10, 2 );

        // Descriptor on thank-you page and emails
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'show_descriptor_thankyou' ), 5 );
        add_action( 'woocommerce_email_after_order_table', array( $this, 'show_descriptor_email' ), 10, 4 );

        // Percentage fee
        add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_percentage_fee' ) );
        add_action( 'wp_footer', array( $this, 'checkout_refresh_script' ) );
    }

    public function get_icon() {
        $visa = MCPG_PLUGIN_URL . 'assets/img/visa.svg';
        $mc   = MCPG_PLUGIN_URL . 'assets/img/mastercard.svg';
        $html = '<img src="' . esc_url( $visa ) . '" alt="Visa" style="max-height:26px;display:inline-block;vertical-align:middle;margin-left:6px" />';
        $html .= '<img src="' . esc_url( $mc ) . '" alt="Mastercard" style="max-height:26px;display:inline-block;vertical-align:middle;margin-left:4px" />';
        return apply_filters( 'woocommerce_gateway_icon', $html, $this->id );
    }

    /* ═══════════════════ ADMIN SETTINGS ═══════════════════ */
    public function init_form_fields() {
        $this->form_fields = array(

            /* ── TAB: General ── */
            'tab_general_start' => array(
                'type' => 'title',
                'title' => '',
                'class' => 'mcpg-tab-content mcpg-tab-general',
            ),
            'general_heading' => array(
                'title' => '<span class="mcpg-section-title">General Settings</span>',
                'type'  => 'title',
                'description' => 'Basic gateway configuration — enable the gateway, set what customers see at checkout, and configure debug logging.',
            ),
            'enabled' => array(
                'title'   => 'Enable Gateway',
                'type'    => 'checkbox',
                'label'   => 'Enable Cascading Payment Gateway',
                'default' => 'no',
            ),
            'title' => array(
                'title'   => 'Checkout Title',
                'type'    => 'text',
                'default' => 'Credit / Debit Card',
                'description' => 'Title shown to customer at checkout.',
                'desc_tip' => true,
            ),
            'description' => array(
                'title'   => 'Checkout Description',
                'type'    => 'textarea',
                'default' => 'Pay securely with your Visa or Mastercard.',
                'description' => 'Description shown below the title at checkout.',
                'desc_tip' => true,
            ),
            'debug' => array(
                'title'   => 'Debug Log',
                'type'    => 'checkbox',
                'label'   => 'Enable logging',
                'default' => 'yes',
                'description' => 'Log events to <strong>WooCommerce &gt; Status &gt; Logs</strong> (filenames starting with <code>mcpg-</code>). Disable in production for performance.',
            ),

            // Cascade config
            'cascade_heading' => array(
                'title'       => '<span class="mcpg-section-title">Cascade Configuration</span>',
                'type'        => 'title',
                'description' => 'Define the order in which processors are attempted. Each enabled processor is tried in sequence until one approves the payment.',
            ),
            'cascade_order' => array(
                'title'       => 'Cascade Order',
                'type'        => 'text',
                'description' => 'Comma-separated processor IDs in attempt order.<br>Available: <code>vp2d</code> (V-Processor 2D), <code>ep2d</code> (E-Processor 2D), <code>vp3d</code> (V-Processor 3D)',
                'default'     => 'vp2d,ep2d,vp3d',
            ),

            // Fees
            'fee_heading' => array(
                'title' => '<span class="mcpg-section-title">Checkout Fees</span>',
                'type'  => 'title',
                'description' => 'Add a percentage fee to the order total when this gateway is selected.',
            ),
            'percentage_on_top' => array(
                'title'       => 'Percentage Fee (%)',
                'type'        => 'number',
                'description' => 'Additional percentage fee added at checkout. Leave empty or 0 for no fee.',
                'default'     => '',
                'desc_tip'    => true,
                'css'         => 'width:100px;',
                'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
            ),
            'fee_label' => array(
                'title'   => 'Fee Label',
                'type'    => 'text',
                'default' => 'Transaction Fee',
                'description' => 'Label shown on the checkout page for the fee line item.',
                'desc_tip' => true,
            ),

            /* ── TAB: Processors ── */
            'tab_processors_start' => array(
                'type' => 'title',
                'title' => '',
                'class' => 'mcpg-tab-content mcpg-tab-processors',
            ),

            // VP2D
            'vp2d_heading' => array(
                'title'       => '<span class="mcpg-section-title">V-Processor 2D (VP2D)</span>',
                'type'        => 'title',
                'description' => 'Direct card processing via vSafe — no 3DS redirect. Fastest checkout experience.',
            ),
            'vp2d_enabled' => array(
                'title'   => 'Enable',
                'type'    => 'checkbox',
                'label'   => 'Include in cascade',
                'default' => 'yes',
            ),
            'vp2d_environment' => array(
                'title'   => 'Environment',
                'type'    => 'select',
                'options' => array( 'sandbox' => 'Sandbox (Testing)', 'live' => 'Live (Production)' ),
                'default' => 'sandbox',
                'description' => 'Switch to Live when you are ready to accept real payments.',
                'desc_tip' => true,
            ),
            'vp2d_merchant_id' => array(
                'title' => 'Merchant ID',
                'type'  => 'text',
                'description' => 'Your vSafe merchant ID for 2D processing.',
                'desc_tip' => true,
            ),
            'vp2d_api_key' => array(
                'title' => 'API Token',
                'type'  => 'password',
                'description' => 'Your vSafe API token (used for request signing).',
                'desc_tip' => true,
            ),
            'vp2d_descriptor' => array(
                'title'       => 'Statement Descriptor',
                'type'        => 'text',
                'description' => 'Text that appears on the customer\'s bank/card statement.',
                'default'     => '',
                'desc_tip'    => true,
            ),

            // EP2D
            'ep2d_heading' => array(
                'title'       => '<span class="mcpg-section-title">E-Processor 2D (EP2D)</span>',
                'type'        => 'title',
                'description' => 'Direct card processing via EuPaymentz — no 3DS redirect.',
            ),
            'ep2d_enabled' => array(
                'title'   => 'Enable',
                'type'    => 'checkbox',
                'label'   => 'Include in cascade',
                'default' => 'yes',
            ),
            'ep2d_environment' => array(
                'title'   => 'Environment',
                'type'    => 'select',
                'options' => array( 'sandbox' => 'Sandbox (Testing)', 'live' => 'Live (Production)' ),
                'default' => 'sandbox',
                'description' => 'Sandbox/live mode is controlled on the provider\'s side. This toggle controls test card substitution only.',
                'desc_tip'    => true,
            ),
            'ep2d_account_id' => array(
                'title' => 'Account ID',
                'type'  => 'text',
                'description' => 'Your EuPaymentz account ID.',
                'desc_tip' => true,
            ),
            'ep2d_account_password' => array(
                'title' => 'Account Password',
                'type'  => 'password',
                'description' => 'Your EuPaymentz account password.',
                'desc_tip' => true,
            ),
            'ep2d_account_passphrase' => array(
                'title'       => 'SHA Passphrase',
                'type'        => 'password',
                'description' => 'Used for SHA256 hash verification of responses.',
                'desc_tip'    => true,
            ),
            'ep2d_account_gateway' => array(
                'title'   => 'Gateway Account',
                'type'    => 'text',
                'default' => '1',
                'description' => 'Gateway account number (usually "1").',
                'desc_tip' => true,
            ),
            'ep2d_transaction_prefix' => array(
                'title'   => 'Transaction Prefix',
                'type'    => 'text',
                'default' => 'MCPG-',
                'description' => 'Prefix added to transaction IDs sent to the processor.',
                'desc_tip' => true,
            ),
            'ep2d_descriptor' => array(
                'title'       => 'Statement Descriptor',
                'type'        => 'text',
                'description' => 'Text that appears on the customer\'s bank/card statement.',
                'default'     => '',
                'desc_tip'    => true,
            ),

            // VP3D
            'vp3d_heading' => array(
                'title'       => '<span class="mcpg-section-title">V-Processor 3D (VP3D)</span>',
                'type'        => 'title',
                'description' => '3D-Secure card processing via vSafe. Customer may be redirected to their bank for verification. Highest approval rates for supported cards.',
            ),
            'vp3d_enabled' => array(
                'title'   => 'Enable',
                'type'    => 'checkbox',
                'label'   => 'Include in cascade',
                'default' => 'yes',
            ),
            'vp3d_testmode' => array(
                'title'   => 'Environment',
                'type'    => 'checkbox',
                'label'   => 'Enable Sandbox (Testing) mode',
                'default' => 'yes',
                'description' => 'When checked, uses sandbox credentials. Uncheck for live/production.',
            ),
            'vp3d_test_merchant_id' => array(
                'title' => 'Sandbox Merchant ID',
                'type'  => 'text',
                'description' => 'Merchant ID for the sandbox environment.',
                'desc_tip' => true,
            ),
            'vp3d_test_api_token' => array(
                'title' => 'Sandbox API Token',
                'type'  => 'password',
                'description' => 'API token for the sandbox environment.',
                'desc_tip' => true,
            ),
            'vp3d_live_merchant_id' => array(
                'title' => 'Live Merchant ID',
                'type'  => 'text',
                'description' => 'Merchant ID for live/production.',
                'desc_tip' => true,
            ),
            'vp3d_live_api_token' => array(
                'title' => 'Live API Token',
                'type'  => 'password',
                'description' => 'API token for live/production.',
                'desc_tip' => true,
            ),
            'vp3d_descriptor' => array(
                'title'       => 'Statement Descriptor',
                'type'        => 'text',
                'description' => 'Text that appears on the customer\'s bank/card statement.',
                'default'     => '',
                'desc_tip'    => true,
            ),

            // VP3D URLs (readonly, for reference)
            'vp3d_urls_heading' => array(
                'title'       => '<span class="mcpg-section-title" style="font-size:13px;">VP3D Webhook & Return URLs</span>',
                'type'        => 'title',
                'description' => 'Copy these URLs into your vSafe merchant dashboard.',
            ),
            'vp3d_webhook_url' => array(
                'title'       => 'Webhook URL',
                'type'        => 'text',
                'default'     => home_url( '/wc-api/vsafe_webhook' ),
                'description' => '<code>' . home_url( '/wc-api/vsafe_webhook' ) . '</code>',
                'custom_attributes' => array( 'readonly' => 'readonly' ),
                'css' => 'color:#666;background:#f6f7f7;',
            ),
            'vp3d_redirect_url' => array(
                'title'       => '3DS Return URL',
                'type'        => 'text',
                'default'     => home_url( '/wc-api/vsafe_3ds_return' ),
                'description' => '<code>' . home_url( '/wc-api/vsafe_3ds_return' ) . '</code>',
                'custom_attributes' => array( 'readonly' => 'readonly' ),
                'css' => 'color:#666;background:#f6f7f7;',
            ),

            /* ── TAB: Test Cards ── */
            'tab_testcards_start' => array(
                'type' => 'title',
                'title' => '',
                'class' => 'mcpg-tab-content mcpg-tab-testcards',
            ),
            'testcards_info' => array(
                'title' => '<span class="mcpg-section-title">Sandbox Test Cards</span>',
                'type'  => 'title',
                'description' => 'When a processor is in <strong>Sandbox</strong> mode and a test card is configured below, the customer\'s real card details are replaced with these test card details before sending to the processor API.<br><br>Leave fields empty to send the customer\'s actual card even in sandbox mode.',
            ),

            // VP2D test card
            'vp2d_tc_heading' => array(
                'title' => '<span class="mcpg-section-title" style="font-size:14px;">VP2D Test Card</span>',
                'type'  => 'title',
            ),
            'vp2d_test_card_number' => array(
                'title'   => 'Card Number',
                'type'    => 'text',
                'default' => '',
                'css'     => 'width:220px;',
            ),
            'vp2d_test_card_expiry' => array(
                'title'       => 'Expiry (MM/YY)',
                'type'        => 'text',
                'default'     => '',
                'css'         => 'width:100px;',
            ),
            'vp2d_test_card_cvv' => array(
                'title'   => 'CVV',
                'type'    => 'text',
                'default' => '',
                'css'     => 'width:80px;',
            ),
            'vp2d_test_card_name' => array(
                'title'   => 'Cardholder Name',
                'type'    => 'text',
                'default' => '',
                'css'     => 'width:220px;',
            ),

            // EP2D test card
            'ep2d_tc_heading' => array(
                'title' => '<span class="mcpg-section-title" style="font-size:14px;">EP2D Test Card</span>',
                'type'  => 'title',
                'description' => 'Common EuPaymentz test cards: <code>4444333322221111</code> (Accepted), <code>4444333322221210</code> (3DS Accepted), <code>4444333322222101</code> (Refused). Expiry: <code>06/25</code>, CVV: <code>123</code>',
            ),
            'ep2d_test_card_number' => array(
                'title'   => 'Card Number',
                'type'    => 'text',
                'default' => '',
                'css'     => 'width:220px;',
            ),
            'ep2d_test_card_expiry' => array(
                'title'       => 'Expiry (MM/YY)',
                'type'        => 'text',
                'default'     => '',
                'css'         => 'width:100px;',
            ),
            'ep2d_test_card_cvv' => array(
                'title'   => 'CVV',
                'type'    => 'text',
                'default' => '',
                'css'     => 'width:80px;',
            ),
            'ep2d_test_card_name' => array(
                'title'   => 'Cardholder Name',
                'type'    => 'text',
                'default' => '',
                'css'     => 'width:220px;',
            ),

            // VP3D test card
            'vp3d_tc_heading' => array(
                'title' => '<span class="mcpg-section-title" style="font-size:14px;">VP3D Test Card</span>',
                'type'  => 'title',
            ),
            'vp3d_test_card_number' => array(
                'title'   => 'Card Number',
                'type'    => 'text',
                'default' => '',
                'css'     => 'width:220px;',
            ),
            'vp3d_test_card_expiry' => array(
                'title'       => 'Expiry (MM/YY)',
                'type'        => 'text',
                'default'     => '',
                'css'         => 'width:100px;',
            ),
            'vp3d_test_card_cvv' => array(
                'title'   => 'CVV',
                'type'    => 'text',
                'default' => '',
                'css'     => 'width:80px;',
            ),
            'vp3d_test_card_name' => array(
                'title'   => 'Cardholder Name',
                'type'    => 'text',
                'default' => '',
                'css'     => 'width:220px;',
            ),
        );
    }

    /**
     * Custom admin options page with tabbed layout.
     */
    public function admin_options() {
        $tabs = array(
            'general'    => 'General',
            'processors' => 'Processors',
            'testcards'  => 'Test Cards',
        );
        ?>
        <style>
            .mcpg-admin-header {
                background: linear-gradient(135deg, #4f46e5, #7c3aed);
                color: #fff; padding: 24px 28px; border-radius: 10px; margin-bottom: 0;
            }
            .mcpg-admin-header h2 { margin: 0 0 6px; font-size: 22px; font-weight: 700; color: #fff; }
            .mcpg-admin-header p { margin: 0; opacity: 0.85; font-size: 14px; }
            .mcpg-admin-header .mcpg-version {
                display: inline-block; background: rgba(255,255,255,0.2);
                padding: 2px 10px; border-radius: 12px; font-size: 12px; margin-left: 8px;
            }
            /* Tabs */
            .mcpg-tabs {
                display: flex; gap: 0; background: #f0f0f1; border-radius: 0 0 10px 10px;
                padding: 0 12px; margin-bottom: 24px; border-top: 1px solid rgba(255,255,255,0.15);
            }
            .mcpg-tab {
                padding: 12px 22px; cursor: pointer; font-size: 14px; font-weight: 500;
                color: #50575e; border-bottom: 3px solid transparent; transition: all 0.2s;
                user-select: none;
            }
            .mcpg-tab:hover { color: #1d2327; background: rgba(0,0,0,0.03); }
            .mcpg-tab.active {
                color: #4f46e5; border-bottom-color: #4f46e5; font-weight: 600;
                background: rgba(79,70,229,0.05);
            }
            /* Tab content visibility */
            .mcpg-settings-wrap .form-table { display: none; }
            .mcpg-settings-wrap .form-table.mcpg-visible { display: table; }
            /* Section titles */
            .mcpg-section-title { font-size: 16px; font-weight: 700; color: #1d2327; }
            /* Status badges in processor tab */
            .mcpg-env-badge {
                display: inline-block; padding: 2px 8px; border-radius: 4px;
                font-size: 11px; font-weight: 600; text-transform: uppercase; margin-left: 6px;
            }
            .mcpg-env-sandbox { background: #fef3c7; color: #92400e; }
            .mcpg-env-live { background: #d1fae5; color: #065f46; }
        </style>

        <div class="mcpg-admin-header">
            <h2>Cascading Payment Gateway <span class="mcpg-version">v<?php echo esc_html( MCPG_VERSION ); ?></span></h2>
            <p>Automatically routes payments through multiple processors for maximum approval rates.</p>
        </div>

        <div class="mcpg-tabs">
            <?php foreach ( $tabs as $key => $label ) : ?>
                <div class="mcpg-tab<?php echo $key === 'general' ? ' active' : ''; ?>" data-tab="<?php echo esc_attr( $key ); ?>">
                    <?php echo esc_html( $label ); ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="mcpg-settings-wrap">
            <?php
            // We need to render settings grouped by tab.
            // WC generates one <table> per title field. We'll render all and use JS to show/hide.
            $this->generate_settings_html();
            ?>
        </div>

        <script>
        (function($) {
            var fields = <?php echo wp_json_encode( array_keys( $this->form_fields ) ); ?>;
            var tabMap = {};
            var currentTab = 'general';

            // Build a map of which field belongs to which tab
            fields.forEach(function(key) {
                if (key === 'tab_general_start') currentTab = 'general';
                else if (key === 'tab_processors_start') currentTab = 'processors';
                else if (key === 'tab_testcards_start') currentTab = 'testcards';
                tabMap[key] = currentTab;
            });

            function showTab(tab) {
                // Find all table rows and their parent tables
                var $wrap = $('.mcpg-settings-wrap');
                // Hide all rows first
                $wrap.find('tr').hide();
                $wrap.find('h2, p.description, table.form-table').hide();

                // Show rows belonging to this tab
                var showing = false;
                var $allRows = $wrap.find('tr[valign="top"], tr:not([valign])');

                // WC renders each field as a <tr> with id="woocommerce_mcpg_cascading_FIELDKEY"
                // Title fields render as </table><h2>...<table>
                // We need to traverse DOM sequentially
                var $elements = $wrap.children();
                var inTab = false;

                $elements.each(function() {
                    var $el = $(this);

                    if ($el.is('table')) {
                        // Check the first row's field ID to determine tab
                        var $rows = $el.find('tr');
                        var tabForTable = null;

                        $rows.each(function() {
                            var id = $(this).find('[id^="woocommerce_mcpg_cascading_"]').attr('id');
                            if (id) {
                                var fieldKey = id.replace('woocommerce_mcpg_cascading_', '');
                                if (tabMap[fieldKey]) {
                                    tabForTable = tabMap[fieldKey];
                                    return false;
                                }
                            }
                        });

                        if (tabForTable === tab) {
                            $el.show().addClass('mcpg-visible');
                            $el.find('tr').show();
                            inTab = true;
                        } else {
                            $el.hide().removeClass('mcpg-visible');
                            inTab = false;
                        }
                    } else if ($el.is('h2') || $el.is('p')) {
                        if (inTab) $el.show();
                    }
                });

                // Update active tab
                $('.mcpg-tab').removeClass('active');
                $('.mcpg-tab[data-tab="' + tab + '"]').addClass('active');

                // Hide tab marker rows (they have no visible content)
                $wrap.find('tr').filter(function() {
                    return $(this).find('td, th').length === 0;
                }).hide();
            }

            // Tab click handler
            $('.mcpg-tab').on('click', function() {
                showTab($(this).data('tab'));
            });

            // Initial state
            showTab('general');
        })(jQuery);
        </script>
        <?php
    }

    /* ═══════════════════ CHECKOUT CARD FORM ═══════════════════ */
    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }
        ?>
        <fieldset id="mcpg-card-form" class="mcpg-card-form">
            <div class="mcpg-field">
                <label>Cardholder Name <span class="required">*</span></label>
                <input type="text" name="mcpg_card_name" autocomplete="cc-name" placeholder="Name on card" required />
            </div>
            <div class="mcpg-field">
                <label>Card Number <span class="required">*</span></label>
                <input type="text" name="mcpg_card_number" inputmode="numeric" autocomplete="cc-number" placeholder="0000 0000 0000 0000" maxlength="23" required />
            </div>
            <div class="mcpg-row">
                <div class="mcpg-field">
                    <label>Expiry <span class="required">*</span></label>
                    <input type="text" name="mcpg_expiry" inputmode="numeric" autocomplete="cc-exp" placeholder="MM / YY" maxlength="7" required />
                </div>
                <div class="mcpg-field">
                    <label>CVC <span class="required">*</span></label>
                    <input type="text" name="mcpg_cvv" inputmode="numeric" autocomplete="cc-csc" placeholder="&bull;&bull;&bull;" maxlength="4" required />
                </div>
            </div>
            <div class="mcpg-secure-badge">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span>Secured with 256-bit encryption &mdash; your card details are never stored</span>
            </div>
        </fieldset>
        <?php
    }

    public function validate_fields() {
        $errors = array();

        $name   = sanitize_text_field( $_POST['mcpg_card_name'] ?? '' );
        $number = preg_replace( '/\D/', '', sanitize_text_field( $_POST['mcpg_card_number'] ?? '' ) );
        $expiry = preg_replace( '/\D/', '', sanitize_text_field( $_POST['mcpg_expiry'] ?? '' ) );
        $cvv    = preg_replace( '/\D/', '', sanitize_text_field( $_POST['mcpg_cvv'] ?? '' ) );

        if ( empty( $name ) ) {
            $errors[] = 'Cardholder name is required.';
        }
        if ( empty( $number ) || strlen( $number ) < 13 || strlen( $number ) > 19 ) {
            $errors[] = 'Please enter a valid card number.';
        }
        if ( strlen( $expiry ) !== 4 ) {
            $errors[] = 'Please enter a valid expiry date (MM/YY).';
        } else {
            $month = (int) substr( $expiry, 0, 2 );
            if ( $month < 1 || $month > 12 ) {
                $errors[] = 'Please enter a valid expiry month (01-12).';
            }
        }
        if ( empty( $cvv ) || strlen( $cvv ) < 3 || strlen( $cvv ) > 4 ) {
            $errors[] = 'Please enter a valid CVC.';
        }

        foreach ( $errors as $err ) {
            wc_add_notice( $err, 'error' );
        }

        return empty( $errors );
    }

    /* ═══════════════════ PROCESS PAYMENT ═══════════════════ */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        $this->logger->log( '=== MCPG PAYMENT START === Order #' . $order_id );

        // Extract card data from POST
        $card_number = preg_replace( '/\D/', '', sanitize_text_field( $_POST['mcpg_card_number'] ?? '' ) );
        $expiry      = preg_replace( '/\D/', '', sanitize_text_field( $_POST['mcpg_expiry'] ?? '' ) );
        $cvv         = preg_replace( '/\D/', '', sanitize_text_field( $_POST['mcpg_cvv'] ?? '' ) );
        $name        = sanitize_text_field( $_POST['mcpg_card_name'] ?? '' );

        $card_data = array(
            'name'      => $name,
            'number'    => $card_number,
            'exp_month' => (int) substr( $expiry, 0, 2 ),
            'exp_year'  => (int) substr( $expiry, 2, 2 ),
            'cvv'       => $cvv,
        );

        $this->logger->log( 'Card (masked): ' . substr( $card_number, 0, 6 ) . '****' . substr( $card_number, -4 ) );

        // Store encrypted card data for cascade
        if ( ! MCPG_Card_Store::store( $order_id, $card_data ) ) {
            $this->logger->log( 'ERROR: Failed to store encrypted card data' );
            wc_add_notice( 'A security error occurred. Please try again.', 'error' );
            return array( 'result' => 'failure' );
        }

        // Initialize cascade state
        $processors = MCPG_Cascade_Engine::init_cascade( $order_id );

        if ( empty( $processors ) ) {
            $this->logger->log( 'ERROR: No processors enabled in cascade' );
            MCPG_Card_Store::destroy( $order_id );
            wc_add_notice( 'Payment is currently unavailable. Please contact support.', 'error' );
            return array( 'result' => 'failure' );
        }

        // Set order to pending
        $order->update_status( 'pending', 'Cascade payment initiated with ' . count( $processors ) . ' processor(s).' );

        // Empty cart
        WC()->cart->empty_cart();

        // Redirect to order-received page with cascade flag
        $cascade_url = add_query_arg( array(
            'mcpg_cascade' => '1',
        ), $this->get_return_url( $order ) );

        $this->logger->log( 'Redirecting to cascade page: ' . $cascade_url );

        return array( 'result' => 'success', 'redirect' => $cascade_url );
    }

    /* ═══════════════════ CASCADE OVERLAY ═══════════════════ */
    public function render_cascade_overlay( $order_id ) {
        if ( ! isset( $_GET['mcpg_cascade'] ) || $_GET['mcpg_cascade'] !== '1' ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== $this->id ) return;

        // If order already completed (e.g. page refresh after success), skip
        if ( $order->has_status( array( 'processing', 'completed' ) ) ) return;

        $processors   = $order->get_meta( '_mcpg_cascade_processors' ) ?: array();
        $total_steps  = count( $processors );
        $current_step = (int) $order->get_meta( '_mcpg_cascade_step' );

        wp_enqueue_script( 'mcpg-cascade', MCPG_PLUGIN_URL . 'assets/js/mcpg-cascade.js', array( 'jquery' ), MCPG_VERSION, true );
        wp_localize_script( 'mcpg-cascade', 'mcpg_cascade', array(
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'mcpg_cascade_nonce' ),
            'order_id'      => $order_id,
            'order_key'     => $order->get_order_key(),
            'total_steps'   => $total_steps,
            'current_step'  => $current_step,
            'thankyou_url'  => remove_query_arg( 'mcpg_cascade', $this->get_return_url( $order ) ),
            'checkout_url'  => wc_get_checkout_url(),
            'step_delay'    => 1500, // ms between steps for UX
        ));

        wp_enqueue_style( 'mcpg-cascade-css', MCPG_PLUGIN_URL . 'assets/css/mcpg-cascade.css', array(), MCPG_VERSION );

        // Build step labels for the UI
        $step_labels = array();
        for ( $i = 0; $i < $total_steps; $i++ ) {
            $step_labels[] = 'Payment Route ' . ( $i + 1 );
        }
        ?>
        <div id="mcpg-cascade-overlay">
            <div class="mcpg-cascade-container">
                <!-- Header -->
                <div class="mcpg-cascade-header">
                    <div class="mcpg-cascade-lock">
                        <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                    </div>
                    <h2 class="mcpg-cascade-title">Processing Your Payment</h2>
                    <p class="mcpg-cascade-subtitle">We're securely routing your payment for the best result. Please don't close this window.</p>
                </div>

                <!-- Progress bar -->
                <div class="mcpg-cascade-progress">
                    <div class="mcpg-cascade-progress-bar" id="mcpg-progress-bar"></div>
                </div>

                <!-- Steps -->
                <div class="mcpg-cascade-steps" id="mcpg-steps">
                    <?php for ( $i = 0; $i < $total_steps; $i++ ) : ?>
                    <div class="mcpg-step" id="mcpg-step-<?php echo $i; ?>" data-step="<?php echo $i; ?>">
                        <div class="mcpg-step-icon" id="mcpg-step-icon-<?php echo $i; ?>">
                            <span class="mcpg-step-number"><?php echo $i + 1; ?></span>
                        </div>
                        <div class="mcpg-step-content">
                            <div class="mcpg-step-label"><?php echo esc_html( $step_labels[ $i ] ); ?></div>
                            <div class="mcpg-step-status" id="mcpg-step-status-<?php echo $i; ?>">Waiting</div>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>

                <!-- Status message -->
                <div class="mcpg-cascade-message" id="mcpg-message">
                    Initiating secure payment processing...
                </div>

                <!-- Warning -->
                <div class="mcpg-cascade-warning">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span>Do not press back or close this window</span>
                </div>

                <!-- Result (hidden initially) -->
                <div class="mcpg-cascade-result" id="mcpg-result" style="display:none;">
                    <div class="mcpg-result-icon" id="mcpg-result-icon"></div>
                    <h3 class="mcpg-result-title" id="mcpg-result-title"></h3>
                    <p class="mcpg-result-message" id="mcpg-result-message"></p>
                    <a class="mcpg-result-button" id="mcpg-result-button" href="#"></a>
                </div>
            </div>
        </div>
        <?php
    }

    /* ═══════════════════ AJAX CASCADE HANDLER ═══════════════════ */
    public function ajax_cascade_process() {
        check_ajax_referer( 'mcpg_cascade_nonce', 'nonce' );

        $order_id  = absint( $_POST['order_id'] ?? 0 );
        $order_key = sanitize_text_field( $_POST['order_key'] ?? '' );

        if ( ! $order_id || ! $order_key ) {
            wp_send_json_error( array( 'message' => 'Invalid request.' ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_order_key() !== $order_key ) {
            wp_send_json_error( array( 'message' => 'Invalid order.' ) );
        }

        // Already completed
        if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
            wp_send_json_success( array(
                'status'       => 'approved',
                'redirect_url' => remove_query_arg( 'mcpg_cascade', $this->get_return_url( $order ) ),
            ));
        }

        // Process next step
        $result = MCPG_Cascade_Engine::process_step( $order_id );

        if ( $result['status'] === 'approved' ) {
            wp_send_json_success( array(
                'status'       => 'approved',
                'step'         => $result['step'],
                'total'        => $result['total'],
                'redirect_url' => remove_query_arg( 'mcpg_cascade', $this->get_return_url( $order ) ),
            ));
        }

        if ( $result['status'] === '3ds_redirect' ) {
            wp_send_json_success( array(
                'status'       => '3ds_redirect',
                'step'         => $result['step'],
                'total'        => $result['total'],
                'redirect_url' => $result['redirect_url'],
                'message'      => 'Redirecting to card verification...',
            ));
        }

        if ( $result['status'] === 'pending' ) {
            wp_send_json_success( array(
                'status'  => 'pending',
                'step'    => $result['step'],
                'total'   => $result['total'],
                'message' => 'Awaiting confirmation...',
            ));
        }

        if ( $result['status'] === 'exhausted' ) {
            wp_send_json_success( array(
                'status'  => 'exhausted',
                'step'    => $result['step'],
                'total'   => $result['total'],
                'message' => 'We were unable to process your payment. Your order has been placed on hold. Please try again or contact support.',
            ));
        }

        // Failed but more processors to try
        wp_send_json_success( array(
            'status'  => 'failed',
            'step'    => $result['step'],
            'total'   => $result['total'],
            'message' => $result['message'] ?? 'Attempting next route...',
        ));
    }

    /* ═══════════════════ REFUND ═══════════════════ */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order     = wc_get_order( $order_id );
        $processor = $order->get_meta( '_mcpg_payment_processor' );
        $tx_id     = $order->get_meta( '_mcpg_transaction_id' );
        $settings  = MCPG_Cascade_Engine::settings();

        if ( ! $tx_id ) {
            return new WP_Error( 'no_tx', 'No transaction ID found for refund.' );
        }

        $this->logger->log( 'Refund request: Order #' . $order_id . ' via ' . $processor . ' TX: ' . $tx_id . ' Amount: ' . $amount );

        switch ( $processor ) {
            case 'vp2d':
                return $this->refund_vp2d( $order, $tx_id, $amount, $reason, $settings );
            case 'vp3d':
                return $this->refund_vp3d( $order, $tx_id, $amount, $reason, $settings );
            case 'ep2d':
                return $this->refund_ep2d( $order, $tx_id, $amount, $reason, $settings );
            default:
                return new WP_Error( 'unknown_processor', 'Cannot determine which processor handled this payment.' );
        }
    }

    private function refund_vp2d( $order, $tx_id, $amount, $reason, $settings ) {
        $endpoint = MCPG_VProcessor_API::endpoint( $settings['vp2d_environment'] ?? 'sandbox', 'refunds' );
        $body = array(
            'serviceSecurity'    => array( 'merchantId' => (int) ( $settings['vp2d_merchant_id'] ?? '' ) ),
            'transactionDetails' => array(
                'amount'        => (float) $amount,
                'currency'      => strtoupper( $order->get_currency() ),
                'transactionId' => $tx_id,
                'commentaries'  => (string) $reason,
            ),
        );
        $response = MCPG_VProcessor_API::post( $endpoint, $settings['vp2d_api_key'] ?? '', $body );
        if ( is_wp_error( $response ) ) return new WP_Error( 'http', $response->get_error_message() );
        $result = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $result['result']['status'] ) && $result['result']['status'] === 'approved' ) {
            $order->add_order_note( 'Refund approved via VP2D. TX: ' . $tx_id );
            return true;
        }
        return new WP_Error( 'refund_fail', $result['result']['errorDetail'] ?? 'Refund rejected.' );
    }

    private function refund_vp3d( $order, $tx_id, $amount, $reason, $settings ) {
        $testmode = ( $settings['vp3d_testmode'] ?? 'yes' ) === 'yes';
        $env      = $testmode ? 'sandbox' : 'live';
        $token    = $testmode ? ( $settings['vp3d_test_api_token'] ?? '' ) : ( $settings['vp3d_live_api_token'] ?? '' );
        $endpoint = MCPG_VProcessor_API::endpoint( $env, 'refunds', '1' );
        $body = array(
            'serviceSecurity'    => array( 'merchantId' => (int) ( $testmode ? ( $settings['vp3d_test_merchant_id'] ?? '' ) : ( $settings['vp3d_live_merchant_id'] ?? '' ) ) ),
            'transactionDetails' => array(
                'amount'        => (float) $amount,
                'currency'      => $order->get_currency(),
                'transactionId' => $tx_id,
                'commentaries'  => $reason,
            ),
        );
        $response = MCPG_VProcessor_API::post( $endpoint, $token, $body );
        if ( is_wp_error( $response ) ) return new WP_Error( 'http', $response->get_error_message() );
        $result = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $result['result']['status'] ) && $result['result']['status'] === 'approved' ) {
            $order->add_order_note( 'Refund approved via VP3D. TX: ' . $tx_id );
            return true;
        }
        return new WP_Error( 'refund_fail', $result['result']['errorDetail'] ?? 'Refund rejected.' );
    }

    private function refund_ep2d( $order, $tx_id, $amount, $reason, $settings ) {
        $data = array(
            'account_id'       => $settings['ep2d_account_id'] ?? '',
            'account_password' => $settings['ep2d_account_password'] ?? '',
            'account_sha'      => MCPG_EProcessor_API::sha_refund( $settings['ep2d_account_passphrase'] ?? '', $settings['ep2d_account_id'] ?? '', $tx_id ),
            'trans_id'         => $tx_id,
            'option'           => '',
        );
        if ( $amount !== null ) {
            $data['transac_amount'] = number_format( (float) $amount, 2, '.', '' );
        }
        $response = MCPG_EProcessor_API::post( MCPG_EProcessor_API::REFUND_URL, $data );
        $result   = MCPG_EProcessor_API::parse_response( $response );
        if ( ! $result ) return new WP_Error( 'api_error', 'No response from payment gateway.' );
        if ( isset( $result['resp_trans_status'] ) && $result['resp_trans_status'] === '00000' ) {
            $order->add_order_note( 'Refund approved via EP2D. TX: ' . $tx_id );
            return true;
        }
        return new WP_Error( 'refund_fail', $result['resp_trans_description_status'] ?? 'Refund rejected.' );
    }

    /* ═══════════════════ FRONTEND ASSETS ═══════════════════ */
    public function enqueue_checkout_assets() {
        if ( ! is_checkout() && ! is_cart() ) return;
        wp_enqueue_style( 'mcpg-checkout-css', MCPG_PLUGIN_URL . 'assets/css/mcpg-cascade.css', array(), MCPG_VERSION );
        wp_enqueue_script( 'mcpg-card-formatting', MCPG_PLUGIN_URL . 'assets/js/mcpg-card-formatting.js', array(), MCPG_VERSION, true );
    }

    /* ═══════════════════ BLOCK CHECKOUT BRIDGE ═══════════════════ */
    public function process_payment_for_block( $context, &$result ) {
        if ( $context->payment_method !== $this->id ) return;
        $pd = isset( $context->payment_data ) ? $context->payment_data : array();
        $map = array(
            'mcpg_card_name'   => 'mcpg_card_name',
            'mcpg_card_number' => 'mcpg_card_number',
            'mcpg_expiry'      => 'mcpg_expiry',
            'mcpg_cvv'         => 'mcpg_cvv',
        );
        foreach ( $map as $k => $v ) {
            if ( isset( $pd[ $k ] ) ) {
                $_POST[ $v ] = sanitize_text_field( $pd[ $k ] );
            }
        }
    }

    /* ═══════════════════ DESCRIPTOR ═══════════════════ */
    private function get_order_descriptor( $order ) {
        $processor = $order->get_meta( '_mcpg_payment_processor' );
        if ( $processor ) {
            $descriptor = $this->get_option( $processor . '_descriptor', '' );
            if ( ! empty( $descriptor ) ) return $descriptor;
        }
        return '';
    }

    public function show_descriptor_thankyou( $order_id ) {
        if ( isset( $_GET['mcpg_cascade'] ) ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== $this->id ) return;
        $descriptor = $this->get_order_descriptor( $order );
        if ( empty( $descriptor ) ) return;
        $msg = sprintf(
            'Your payment has been processed securely. The charge will appear on your statement as "%s". If you have any questions, please contact our support team.',
            esc_html( $descriptor )
        );
        echo '<div style="background:#f0f7ff;border-left:4px solid #6366f1;padding:14px 18px;margin:16px 0 24px;border-radius:4px;font-size:15px;line-height:1.6;color:#1d2327;">';
        echo wp_kses_post( $msg );
        echo '</div>';
    }

    public function show_descriptor_email( $order, $sent_to_admin, $plain_text, $email ) {
        if ( $order->get_payment_method() !== $this->id ) return;
        $descriptor = $this->get_order_descriptor( $order );
        if ( empty( $descriptor ) ) return;
        $msg = sprintf(
            'Your payment has been processed securely. The charge will appear on your statement as "%s". If you have any questions, please contact our support team.',
            esc_html( $descriptor )
        );
        if ( $plain_text ) {
            echo "\n" . wp_strip_all_tags( $msg ) . "\n\n";
        } else {
            echo '<div style="background:#f0f7ff;border-left:4px solid #6366f1;padding:14px 18px;margin:16px 0;font-size:15px;line-height:1.6;color:#1d2327;">';
            echo wp_kses_post( $msg );
            echo '</div>';
        }
    }

    /* ═══════════════════ PERCENTAGE FEE ═══════════════════ */
    public function add_percentage_fee( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        if ( ! $cart ) return;
        $pct = floatval( $this->get_option( 'percentage_on_top', '' ) );
        if ( $pct <= 0 ) return;
        $chosen = WC()->session->get( 'chosen_payment_method' );
        if ( $chosen !== $this->id ) return;
        $total = $cart->get_cart_contents_total() + $cart->get_shipping_total();
        $fee   = round( $total * ( $pct / 100 ), 2 );
        if ( $fee > 0 ) {
            $label = $this->get_option( 'fee_label', 'Transaction Fee' );
            $cart->add_fee( sprintf( '%s (%s%%)', $label, $pct ), $fee, true );
        }
    }

    public function checkout_refresh_script() {
        if ( ! is_checkout() ) return;
        $pct = floatval( $this->get_option( 'percentage_on_top', '' ) );
        if ( $pct <= 0 ) return;
        ?>
        <script>jQuery(function($){$('form.checkout').on('change','input[name="payment_method"]',function(){$('body').trigger('update_checkout');});});</script>
        <?php
    }
}
