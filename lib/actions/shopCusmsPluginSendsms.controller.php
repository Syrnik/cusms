<?php

/**
 * @package shopCusmsPlugin.controller
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @license http://www.webasyst.com/terms/#eula Webasyst
 *
 */
class shopCusmsPluginSendsmsController extends waJsonController
{

    public function execute()
    {
        $from = waRequest::post('from', NULL, waRequest::TYPE_STRING_TRIM);
        $phone = waRequest::post('phone', NULL, waRequest::TYPE_STRING_TRIM);
        $text = waRequest::post('smstext', NULL, waRequest::TYPE_STRING_TRIM);
        $order_id = waRequest::post('order_id', NULL, waRequest::TYPE_INT);

        if (!$phone) {
            $this->setError(_wp("Invalid phone number"));
        }

        if (!$text) {
            $this->setError(_wp("Invalid text"));
        }

        if ($this->errors) {
            return;
        }

        $sms = new waSMS();
        if ($sms->send($phone, $text, ($from ? $from : NULL))) {

            $this->logOrder($order_id, $phone, $text);

            // Кажется logAction не работает для плагинов. Оставим пока
            // @todo Придумать, как задействовать logAction()

            // @todo Сохранять текстовой лог, если это указано в настройках плагина

        } else {
            $this->setError(_wp("SMS gateway error"));
        }

        $this->response = _wp("Message sent");
    }

    /**
     * Записывает в лог операций с заказом факт отправки сообщения
     *
     * @param string|int $order_id
     * @param string $phone
     */
    private function logOrder($order_id, $phone, $message)
    {
        if ($order_id) {
            $Order = new shopOrderModel();
            $order = $Order->getOrder($order_id);

            if ($order) {
                $OrderLog = new shopOrderLogModel();
                $phoneFormatter = new waContactPhoneFormatter();

                $OrderLog->add(array(
                    'order_id'  => $order_id,
                    'action_id' => '',
                    'text'      => '<i class="icon16 mobile"></i>' .
                        sprintf(_wp("%s <b>sent SMS to the customer on the number %s</b><p>%s</p>"), wa()->getUser()->getName(), $phoneFormatter->format($phone), htmlspecialchars($message)),
                ));
            }
        }
    }

}