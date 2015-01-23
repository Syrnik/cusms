<?php
return array(
    'name'          => _wp('SMS to customer'),
    'img'           => 'img/sms_icon.png',
    'version'       => '1.0.0',
    'vendor'        => '670917',
    'shop_settings' => FALSE,
    'handlers'      =>
        array(
            'backend_order' => 'backendOrderHandler'
        ),
);
