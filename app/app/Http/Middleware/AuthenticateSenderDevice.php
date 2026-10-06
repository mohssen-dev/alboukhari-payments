<?php

namespace App\Http\Middleware;

use App\Models\SenderDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * مصادقة الهاتف المرسِل: `Authorization: Bearer <token>` يُمنح مرة واحدة عند الاقتران.
 * الجهاز الموثَّق يوضع في $request->attributes['senderDevice'].
 */
class AuthenticateSenderDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = SenderDevice::findByToken($request->bearerToken());

        if (! $device || ! $device->is_active) {
            return response()->json(['message' => 'Invalid or revoked device token.'], 401);
        }

        // آخر ظهور — نكتبه كل 30 ثانية على الأكثر حتى لا نثقل القاعدة بكل استطلاع.
        if (! $device->last_seen_at || $device->last_seen_at->lt(now()->subSeconds(30))) {
            $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        $request->attributes->set('senderDevice', $device);

        return $next($request);
    }
}
