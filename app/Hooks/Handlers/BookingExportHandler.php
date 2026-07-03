<?php

namespace FluentBookingPro\App\Hooks\Handlers;

use FluentBooking\App\Services\ExportHelper;
use FluentBookingPro\App\Services\Integrations\PaymentMethods\PaymentHelper;

class BookingExportHandler
{
    public function register()
    {
        add_filter('fluent_booking/booking_export_row', [$this, 'appendPaymentColumns'], 10, 2);
    }

    public function appendPaymentColumns($row, $booking)
    {
        $order = $booking->payment_order ?? null;
        $trans = $order ? $order->transaction : null;

        return array_merge($row, [
            'transaction_type'     => ExportHelper::sanitizeCell($trans ? $trans->transaction_type : ''),
            'order_number'         => ExportHelper::sanitizeCell($order ? $order->order_number : ''),
            'currency'             => ExportHelper::sanitizeCell($order ? $order->currency : ''),
            'subtotal'             => $order ? PaymentHelper::getFormattedAmount($order->subtotal) : '',
            'discount_total'       => $order ? PaymentHelper::getFormattedAmount($order->discount_total) : '',
            'tax_total'            => $order ? PaymentHelper::getFormattedAmount($order->tax_total) : '',
            'total_amount'         => $order ? PaymentHelper::getFormattedAmount($order->total_amount) : '',
            'paid_at'              => $order && $order->completed_at ? (string) $order->completed_at : '',
            'refunded_at'          => $order && $order->refunded_at ? (string) $order->refunded_at : '',
            'vendor_charge_id'     => ExportHelper::sanitizeCell($trans ? $trans->vendor_charge_id : ''),
        ]);
    }
}
