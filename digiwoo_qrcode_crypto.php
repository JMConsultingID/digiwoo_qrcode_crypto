<?php
/**
 * Plugin Name: DigiWoo QRCode Crypto for WooCommerce
 * Description: Integrates LetKnow Crypto Payment Gateway with WooCommerce.
 * Version: 1.0
 * Author: Ardika JM-Consulting
 * Text Domain:digiwoo-qrcode-crypto
 */


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Hook our custom function into WooCommerce's "plugins_loaded" action
add_action('plugins_loaded', 'digiwoo_init_qrcode_crypto_gateway');

function digiwoo_init_qrcode_crypto_gateway() {
    if (!class_exists('WC_Payment_Gateway')) {
        return; // Exit if WooCommerce is not activated.
    }

    class WC_Gateway_DigiWoo_QRCode_Crypto extends WC_Payment_Gateway {
        
        public function __construct() {
            $this->id                 = 'digiwoo_qrcode_crypto';
            $this->method_title       = __('DigiWoo QRCode Crypto', 'digiwoo-qrcode-crypto');
            $this->method_description = __('Accepts cryptocurrency payments.', 'digiwoo-qrcode-crypto');
            $this->has_fields         = false;
            
            // Load the form fields.
            $this->init_form_fields();
            
            // Load the settings.
            $this->init_settings();
            
            $this->enabled = $this->get_option('enabled');
            
            // Save settings.
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __('Enable/Disable', 'digiwoo-qrcode-crypto'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable DigiWoo QRCode Crypto', 'digiwoo-qrcode-crypto'),
                    'default' => 'no'
                ),
            );
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);

            $response = $this->generate_qrcode($order);
            
            if ($response['result'] == 'success') {
                $qr_code_data = $response['qr_code'];
                $address_data = $response['address'];
                // Generate the QR Code using qrcode.js here
                // Add order note with the payment payload
                $order->add_order_note(__('QRCode_Crypto payload generated.', 'digiwoo-qrcode-crypto'));
                update_post_meta( $order_id, 'payment_adress', $address_data );
                // Then mark order as on-hold (waiting for the payment)
                $order->update_status('on-hold', __('Awaiting cryptocurrency payment.', 'digiwoo-qrcode-crypto'));
                
                // Return thankyou redirect
                return array(
                    'result' => 'success',
                    'qr_code' => $qr_code_data,
                    'redirect' => $this->get_return_url($order)
                );
            } else {
                // Log error or show some debug info
                $order->add_order_note(__('QRCode_Crypto payload failed.', 'digiwoo-qrcode-crypto'));
                update_post_meta( $order_id, 'payment_adress', 'load failed' );
                return array('result' => 'failure');
            }
        }

        public function generate_qrcode($order) {
            $requestUrl = 'https://pay.letknow.com/api/2/get_deposit_address';
            $nonce = str_replace('.', '', microtime(true));
            $shopId = 'Xa539bdGVn6nk4CRLpBclC3sZuyJcd';
            $shopKey = 'qe0WpUMr0hf1oodHdgyfM4CSsnYFYv';
            $signature = hash_hmac('sha256', "{$nonce}|{$shopId}|{$shopKey}", $shopKey);

            $requestHeader = [
                "C-Request-Nonce: {$nonce}",
                "C-Request-Signature: {$signature}",
                "C-Shop-Id: {$shopId}",
                "Content-Type: application/json"
            ];

            $request = [
                'currency' => 'BTC',
                'currency_receive' => 'BTC',
                'reference_id' => 'refid_' . $order->get_id(),
                'client' => [
                    'id' => 'client_' . $order->get_user_id(),
                    'first_name' => $order->get_billing_first_name(),
                    'last_name' => $order->get_billing_last_name(),
                    'email' => $order->get_billing_email(),
                    'address' => $order->get_billing_address_1(),
                ],
            ];
            $requestJson = json_encode($request);

            $ch = curl_init($requestUrl);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_HEADER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestJson);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeader);
            $response = curl_exec($ch);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $response = substr($response, $headerSize);
            curl_close($ch);
            $parsedResult = (array)json_decode($response, 1);
            return $parsedResult;
        }
    }

    // Register our new payment gateway
    add_filter('woocommerce_payment_gateways', 'add_digiwoo_qrcode_crypto_gateway');

    function add_digiwoo_qrcode_crypto_gateway($methods) {
        $methods[] = 'WC_Gateway_DigiWoo_QRCode_Crypto';
        return $methods;
    }

    function digiwoo_crypto_enqueue_scripts() {
    // Only enqueue on the checkout page
        if (is_checkout()) {
            wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.js');
            wp_enqueue_style('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css');
            wp_enqueue_script('qrcode', 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js');
        }
    }
    add_action('wp_enqueue_scripts', 'digiwoo_crypto_enqueue_scripts');

    function digiwooo_crypto_inline_js() {
        // Only add the script on the checkout page
        if (is_checkout()) {
            ?>
            <script>
                jQuery(function($) {
                    $('#place_order').on('click', function(e) {
                        if ($('#payment_method_digiwoo_qrcode_crypto').is(':checked')) {
                        e.preventDefault();

                        // Assuming you have AJAX checkout enabled.
                        $.ajax({
                            type: 'POST',
                            url: '<?php echo WC()->ajax_url(); ?>',
                            data: $('form.checkout').serialize(),
                            success: function(response) {
                                if (response.result === 'success' && response.qr_code) {
                                    Swal.fire({
                                        title: 'Scan this QR Code to Pay',
                                        html: '<div id="qrcode"></div>',
                                        onOpen: function() {
                                            new QRCode(document.getElementById('qrcode'), {
                                                text: response.qr_code,
                                                width: 128,
                                                height: 128,
                                            });
                                        },
                                        onClose: function() {
                                            // Redirect to the 'Thank You' page after user closes the modal.
                                            window.location.href = response.redirect;
                                        }
                                    });
                                } else {
                                    // Handle failure or other scenarios here.
                                    alert('Payment processing failed. Please try again.');
                                }
                            }
                        });
                    }
                    });
                });
            </script>
            <?php
        }
    }
    add_action('wp_footer', 'digiwooo_crypto_inline_js');



}

