<?php
namespace Opencart\Admin\Controller\Extension\PaycellPaymentGateway\Payment;

class PaycellPaymentGateway extends \Opencart\System\Engine\Controller 
{
    private $error = array();

    private $settings_fields = array(
        array('name' => 'payment_paycell_payment_gateway_status', 'rules' => ''),
        array('name' => 'payment_paycell_payment_gateway_title', 'rules' => ''),
        array('name' => 'payment_paycell_payment_gateway_sandbox_mode', 'rules' => ''),
        array('name' => 'payment_paycell_payment_gateway_application_name', 'rules' => 'required'),
        array('name' => 'payment_paycell_payment_gateway_application_password', 'rules' => 'required'),
        array('name' => 'payment_paycell_payment_gateway_merchant_code', 'rules' => 'required'),
        array('name' => 'payment_paycell_payment_gateway_secure_code', 'rules' => 'required'),
        array('name' => 'payment_paycell_payment_gateway_order_status_id', 'rules' => ''),
        array('name' => 'payment_paycell_payment_gateway_sort_order', 'rules' => ''),
    );

    public function index()
    {
        $this->language->load('extension/paycell_payment_gateway/payment/paycell_payment_gateway');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_paycell_payment_gateway', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        foreach ($this->settings_fields as $field) {
            $field_name = $field['name'];
            $data["error_{$field_name}"] = isset($this->error[$field_name]) ? $this->error[$field_name] : '';
            $data[$field_name] = isset($this->request->post[$field_name]) ? $this->request->post[$field_name] : $this->config->get($field_name);
        }

        $data['action'] = $this->url->link('extension/paycell_payment_gateway/payment/paycell_payment_gateway', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'], true);
        $this->load->model('localisation/order_status');
        if ($data['payment_paycell_payment_gateway_order_status_id'] == '') {
            $data['payment_paycell_payment_gateway_order_status_id'] = $this->config->get('config_order_status_id');
        }

        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/paycell_payment_gateway/payment/paycell_payment_gateway', $data));
    }


    protected function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/paycell_payment_gateway/payment/paycell_payment_gateway')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        foreach ($this->settings_fields as $field) {
            if (empty($field['rules'])) continue;

            $field_name = $field['name'];
            if ($field['rules'] === 'required' && empty($this->request->post[$field_name])) {
                $field_error = $this->language->get("error_$field_name");
                $error_text = $field_error != "error_$field_name" ? $field_error : $this->language->get("error_required");
                $this->error[$field_name] = $error_text;
            }
        }

        return !$this->error;
    }
}
