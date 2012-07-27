<?php

$capabilities = array(

    'tool/advuserbulk:view' => array(
        'riskbitmask' => RISK_DATALOSS | RISK_PERSONAL | RISK_SPAM,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
        ),
    )
);
