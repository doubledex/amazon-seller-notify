<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SqsMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id', // Unique ID from SQS
        'body',       // The message payload
        'receipt_handle', // For deleting from SQS
        'processed',  // Flag to prevent reprocessing
        'NotificationType', // Add NotificationType
        'Event_Time', // Add Event_Time
        'flagged', // add flagged
    ];

    // Optional: Add timestamps if needed
    // public $timestamps = true;

    /**
     * Scope a query to order messages by event_time.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrderByEventTime($query)
    {
        return $query->orderBy('EventTime');
    }
}