<?php

return [
    'default_unit_value' => (float) env('INBOUND_DISCREPANCY_DEFAULT_UNIT_VALUE', 0),
    'claim_window_days' => (int) env('INBOUND_DISCREPANCY_CLAIM_WINDOW_DAYS', 30),

    'severity' => [
        'value_thresholds' => [
            'medium' => (float) env('INBOUND_DISCREPANCY_VALUE_THRESHOLD_MEDIUM', 75),
            'high' => (float) env('INBOUND_DISCREPANCY_VALUE_THRESHOLD_HIGH', 200),
            'critical' => (float) env('INBOUND_DISCREPANCY_VALUE_THRESHOLD_CRITICAL', 500),
        ],
        'warning_deadline_days' => (int) env('INBOUND_DISCREPANCY_WARNING_DEADLINE_DAYS', 7),
        'urgent_deadline_days' => (int) env('INBOUND_DISCREPANCY_URGENT_DEADLINE_DAYS', 3),
    ],
];
