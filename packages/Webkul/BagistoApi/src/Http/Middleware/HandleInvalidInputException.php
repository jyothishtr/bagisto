<?php

namespace Webkul\BagistoApi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;
use Webkul\BagistoApi\Exception\InvalidInputException;

/**
 * Handle InvalidInputException for REST API
 * Converts validation errors to proper RFC 7807 format
 */
class HandleInvalidInputException
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->sanitizeEmptyGraphQlCursors($request);

            Log::info('HandleInvalidInputException middleware invoked', [
                'path' => $request->path(),
            ]);

            return $next($request);
        } catch (InvalidInputException $e) {
            Log::info('InvalidInputException caught in middleware', [
                'message' => $e->getMessage(),
            ]);
            // Return proper API error response for REST APIs
            if ($request->wantsJson() || $request->is('api/*')) {
                return response()->json([
                    'type'   => $e->getType(),
                    'title'  => $e->getTitle(),
                    'status' => $e->getStatus(),
                    'detail' => $e->getDetail(),
                ], $e->getStatusCode(), [], JSON_UNESCAPED_SLASHES);
            }

            throw $e;
        }
    }

    /**
     * API Platform cursor pagination rejects empty cursor strings.
     * Convert empty cursor inputs to null to treat them as omitted.
     */
    private function sanitizeEmptyGraphQlCursors(Request $request): void
    {
        if (! $request->is('api/graphql')) {
            return;
        }

        $rawContent = $request->getContent();
        if (! is_string($rawContent) || $rawContent === '') {
            return;
        }

        $payload = json_decode($rawContent, true);
        if (! is_array($payload) || $payload === []) {
            return;
        }

        $changed = false;

        if (isset($payload['query']) && is_string($payload['query'])) {
            $sanitizedQuery = preg_replace('/\b(after|before)\s*:\s*""/', '$1: null', $payload['query']);

            if (is_string($sanitizedQuery) && $sanitizedQuery !== $payload['query']) {
                $payload['query'] = $sanitizedQuery;
                $changed = true;
            }
        }

        if (isset($payload['variables']) && is_array($payload['variables'])) {
            foreach (['after', 'before'] as $cursorArg) {
                if (array_key_exists($cursorArg, $payload['variables']) && $payload['variables'][$cursorArg] === '') {
                    $payload['variables'][$cursorArg] = null;
                    $changed = true;
                }
            }
        }

        if (! $changed) {
            return;
        }

        $encodedPayload = json_encode($payload);
        if (! is_string($encodedPayload)) {
            return;
        }

        $request->initialize(
            $request->query->all(),
            $payload,
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $request->server->all(),
            $encodedPayload
        );

        $request->setJson(new InputBag($payload));
    }
}
