<?php
namespace Opencart\Catalog\Model\Extension\PaycellPaymentGateway\Payment;

class PaycellPaymentGateway extends \Opencart\System\Engine\Model
{

    public function getMethods(array $address = []): array
    {
        if (!in_array($this->session->data['currency'], ['TRY'])) {
            return [];
        }

        $this->load->language('extension/paycell_payment_gateway/payment/paycell_payment_gateway');

        $configuredTitle = $this->config->get('payment_paycell_payment_gateway_title');
        $fallbackTitle = 'Pay with Debit/Credit Card';
        $methodTitle = $configuredTitle ? $configuredTitle : $fallbackTitle;
        $logoPrefix = '<img src="extension/paycell_payment_gateway/catalog/view/image/payment/paycell-logo.png" alt="Paycell" style="height:16px;vertical-align:middle;margin-right:6px;" /> ';

        $displayName = $logoPrefix . $methodTitle;

        $option_data['paycell_payment_gateway'] = [
            'code' => 'paycell_payment_gateway.paycell_payment_gateway',
            'name' => $methodTitle
        ];

        return [
            'code'       => 'paycell_payment_gateway',
            'name'       => $displayName,
            'option'     => $option_data,
            'sort_order' => $this->config->get('payment_paycell_payment_gateway_sort_order')
        ];
    }


    public function getOrder($order_id)
    {
        $this->load->model('checkout/order');
        $order_id 			 = $this->db->escape($order_id);
        $order_info = $this->model_checkout_order->getOrder($order_id);
        if (!$order_info) {
            throw new \Exception("Order not found!");
        }
        return $order_info;

    }


}









