<?php
class ModelExtensionPaymentEps extends Model {
    public function getMethod($address, $total) {
        $this->load->language('extension/payment/eps');

        $method_data = array();

        // Only display the payment option if it is enabled in your admin settings
        if ($this->config->get('payment_eps_status')) {
            $method_data = array(
                'code'       => 'eps',
                'title'      => $this->language->get('text_title'),
                'terms'      => '',
                'sort_order' => $this->config->get('payment_eps_sort_order')
            );
        }

        return $method_data;
    }
}