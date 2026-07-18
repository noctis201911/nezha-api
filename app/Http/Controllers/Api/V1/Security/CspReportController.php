<?php

namespace App\Http\Controllers\Api\V1\Security;

use App\Http\Controllers\Controller;
use App\Support\Security\CspReportSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CspReportController extends Controller
{
    public function store(Request $request, CspReportSanitizer $sanitizer): Response
    {
        $mediaType = strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0]));
        if (! in_array($mediaType, ['application/csp-report', 'application/reports+json'], true)) {
            return response()->noContent(Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $content = $request->getContent();
        $declaredLength = (int) $request->header('Content-Length', 0);
        if ($declaredLength > 16 * 1024 || strlen($content) > 16 * 1024) {
            return response()->noContent(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $payload = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return response()->noContent(Response::HTTP_BAD_REQUEST);
        }

        foreach ($sanitizer->sanitize(is_array($payload) ? $payload : [], $mediaType) as $report) {
            $fingerprint = hash('sha256', json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            if (! Cache::add('csp-report:'.$fingerprint, true, now()->addMinutes(10))) {
                continue;
            }
            Log::notice('csp.report_only.violation', $report);
        }

        return response()->noContent();
    }
}
