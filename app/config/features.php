<?php

/*
|--------------------------------------------------------------------------
| Features that are built but hidden until they are wanted
|--------------------------------------------------------------------------
|
| sender_phone — the "sender phone" app (Settings → Sender phone, the
| channel picker on the send page, the channel badge on campaign pages).
| Off by default: nothing about the app shows anywhere on the site and a
| campaign can only go out through BulkGate. Turn it on in .env with
| SENDER_PHONE_ENABLED=true when the app is being worked on. The device
| API (/api/device/*) stays available either way; it is not a page.
|
*/

return [
    'sender_phone' => (bool) env('SENDER_PHONE_ENABLED', false),
];
