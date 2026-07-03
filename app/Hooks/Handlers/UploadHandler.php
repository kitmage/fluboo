<?php

namespace FluentBookingPro\App\Hooks\Handlers;

use FluentBooking\App\App;
use FluentBooking\App\Services\Helper;
use FluentBooking\Framework\Support\Arr;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\BookingFieldService;
use FluentBooking\App\Services\Libs\FileSystem;

class UploadHandler
{
    protected $request;

    public function __construct($app)
    {
        $this->request = $app->request;
        $this->register();
    }

    public function register()
    {
        add_action('wp_ajax_fluent_booking_file_upload', [$this, 'handleFileUpload']);
        add_action('wp_ajax_nopriv_fluent_booking_file_upload', [$this, 'handleFileUpload']);
        add_action('wp_ajax_fluent_booking_file_delete', [$this, 'handleFileDelete']);
        add_action('wp_ajax_nopriv_fluent_booking_file_delete', [$this, 'handleFileDelete']);
        add_action('fluent_booking/before_delete_booking', [$this, 'handleBookingFileCleanup']);
    }

    public function handleFileUpload()
    {
        // Public booking forms must remain anonymous-friendly, but the
        // endpoint must reject everything that is not a real upload to
        // a real, active "file" booking field. Each guard below closes
        // a class of abuse that was previously possible.
        if (!Helper::checkRateLimit('file_upload', 30)) {
            wp_send_json(['errors' => __('Too many requests. Please try again in a minute.', 'fluent-booking-pro')], 429);
        }

        $data  = Arr::except($this->request->all(), ['action']);
        $files = $this->request->files();

        if (!$files) {
            wp_send_json(['errors' => __('No file received', 'fluent-booking-pro')], 400);
        }

        $eventId   = (int) Arr::get($data, 'event_id');
        $fieldName = sanitize_text_field(Arr::get($data, 'field_name'));

        if (!$eventId || !$fieldName) {
            wp_send_json(['errors' => __('Invalid request', 'fluent-booking-pro')], 400);
        }

        $calendarEvent = CalendarSlot::find($eventId);

        if (!$calendarEvent || $calendarEvent->status !== 'active') {
            wp_send_json(['errors' => __('Calendar Event not found', 'fluent-booking-pro')], 422);
        }

        $fieldSettings = BookingFieldService::getBookingFieldByName($calendarEvent, $fieldName);

        // Only enabled "file" booking fields are valid upload targets.
        // Without this guard, a caller could pass field_name=email (or any
        // other text field) — `allow_file_types` would be empty so the
        // mimes: validator rejects extensions, but the upload would still
        // burn server cycles and disk on every request.
        if (
            !$fieldSettings
            || Arr::get($fieldSettings, 'type') !== 'file'
            || !Arr::isTrue($fieldSettings, 'enabled')
        ) {
            wp_send_json(['errors' => __('Field not found', 'fluent-booking-pro')], 422);
        }

        $allowFileTypes = (array) Arr::get($fieldSettings, 'allow_file_types', []);

        if (empty($allowFileTypes)) {
            wp_send_json(['errors' => __('No allowed file types configured', 'fluent-booking-pro')], 422);
        }

        $maxFileUnit  = Arr::get($fieldSettings, 'max_file_unit', 'kb');
        $maxFileValue = (int) Arr::get($fieldSettings, 'max_file_value', 14);
        $maxFileSize  = $maxFileValue * ($maxFileUnit == 'mb' ? (1024 * 1024) : 1024);

        if (in_array('image', $allowFileTypes, true)) {
            $imageTypes     = ['jpg', 'jpeg', 'gif', 'png', 'bmp', 'webp'];
            $allowFileTypes = array_merge($allowFileTypes, $imageTypes);
        }
        $allowFileTypes = array_values(array_unique(array_diff($allowFileTypes, ['image'])));

        $app = App::getInstance();

        $validationConfig = apply_filters('fluent_booking/file_upload_validation_rules_data', [
            'rules'    => [
                'file' => 'max:' . $maxFileSize . '|mimes:' . implode(',', $allowFileTypes)
            ],
            'messages' => [
                'file.max'   => __('Validation fails for maximum file size', 'fluent-booking-pro'),
                'file.mimes' => __('Allowed image types does not match', 'fluent-booking-pro')
            ]
        ], $calendarEvent, $fieldName, $files);

        $validator = $app->validator->make($files, $validationConfig['rules'], $validationConfig['messages']);

        if ($validator->validate()->fails()) {
            wp_send_json(['errors' => $validator->errors()], 422);
        }

        $uploadedFiles = FileSystem::put($files);

        $file = $uploadedFiles[0];

        // Bind delete capability to a token that only the uploader sees.
        // Previously the gate was "do you know the (random) filename",
        // which leaks via the in-flight booking form's HTML and let one
        // anonymous visitor cancel another's upload.
        $fileName    = $file['file'];
        $deleteToken = wp_generate_password(32, false, false);
        set_transient('fcal_upload_' . md5($fileName), $deleteToken, DAY_IN_SECONDS);

        $file['delete_token'] = $deleteToken;

        return wp_send_json(['file' => $file]);
    }

    public function handleFileDelete()
    {
        if (!Helper::checkRateLimit('file_delete', 60)) {
            wp_send_json(['error' => __('Too many requests. Please try again in a minute.', 'fluent-booking-pro')], 429);
        }

        $fileData = Arr::get($this->request->all(), 'file');

        // Frontend sends the file object as an array; extract the basename cleanly.
        $fileName = is_array($fileData)
            ? basename((string) Arr::get($fileData, 'file', ''))
            : basename((string) $fileData);

        $deleteToken = is_array($fileData)
            ? (string) Arr::get($fileData, 'delete_token', '')
            : '';

        if (!$fileName || !$deleteToken) {
            wp_send_json(['error' => __('Invalid request', 'fluent-booking-pro')], 400);
            return;
        }

        $transientKey = 'fcal_upload_' . md5($fileName);
        $storedToken  = get_transient($transientKey);

        if (!is_string($storedToken) || !hash_equals($storedToken, $deleteToken)) {
            wp_send_json(['error' => __('File not found or already deleted', 'fluent-booking-pro')], 403);
            return;
        }

        delete_transient($transientKey);

        FileSystem::delete($fileName);

        return wp_send_json([
            'message' => __('File deleted successfully', 'fluent-booking-pro'),
        ]);
    }

    public function handleBookingFileCleanup($booking)
    {
        $customFieldsData = $booking->getMeta('custom_fields_data', []);
        if (empty($customFieldsData) || !is_array($customFieldsData)) {
            return;
        }

        $event = CalendarSlot::find($booking->event_id);
        if (!$event) {
            return;
        }

        $allFields = BookingFieldService::getBookingFields($event);
        $fileFieldNames = array_flip(array_column(
            array_filter($allFields, fn($f) => ($f['type'] ?? '') === 'file'),
            'name'
        ));

        if (empty($fileFieldNames)) {
            return;
        }

        $basenames = [];
        foreach ($customFieldsData as $fieldName => $value) {
            if (!isset($fileFieldNames[$fieldName])) {
                continue;
            }
            foreach ((array) $value as $url) {
                if ($url) {
                    $basenames[] = basename($url);
                }
            }
        }

        if ($basenames) {
            FileSystem::delete($basenames);
        }
    }

}
