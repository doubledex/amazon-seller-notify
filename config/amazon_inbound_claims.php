<?php

return [
    'defaults' => [
        'program' => 'default',
        'claim_window_days' => 30,
        'priority_thresholds' => [
            'critical_hours' => 48,
            'urgent_hours' => 24,
        ],
        'notification' => [
            'near_expiry_hours' => 48,
            'email_to' => array_filter(array_map('trim', explode(',', (string) env('INBOUND_CLAIMS_ALERT_EMAILS', '')))),
            'slack_webhook_url' => env('INBOUND_CLAIMS_SLACK_WEBHOOK_URL'),
        ],
        'high_priority_tiers' => ['critical', 'urgent', 'missed'],
    ],

    'marketplace_program_overrides' => [
        // 'ATVPDKIKX0DER' => 'fba',
    ],

    'policies' => [
        'default' => [
            'default' => [
                'claim_window_days' => 30,
            ],
        ],

        // Marketplace + program-specific examples:
        // 'ATVPDKIKX0DER' => [
        //     'fba' => ['claim_window_days' => 30],
        //     'seller_fulfilled' => ['claim_window_days' => 21],
        // ],
    ],


    'evidence' => [
        'checklists' => [
            'default' => [
                'required_artifacts' => [
                    'invoice',
                    'bill_of_lading',
                    'proof_of_delivery',
                    'carton_labels',
                    'carton_manifest',
                ],
                'required_virtual_artifacts' => [
                    'shipment_ids',
                    'sku_fnsku_mapping',
                ],
            ],
        ],
        'checksum_algorithm' => 'sha256',
        'default_disk' => env('INBOUND_CLAIMS_EVIDENCE_DISK', 'local'),
    ],

];
