<?php

namespace App\Http\Middleware;

use App\Services\Stocks\InstrumentClassifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanonicalInstrumentRoute
{
    public function __construct(
        protected InstrumentClassifier $classifier
    ) {}

    /**
     * Ensure the requested route matches the instrument's classified asset type,
     * issuing a permanent (301) redirect to the canonical lowercase URL when mismatched.
     */
    public function handle(Request $request, Closure $next, string $expectedType = InstrumentClassifier::TYPE_STOCK): Response
    {
        $rawSymbol = (string) $request->route('symbol', '');
        $symbol = strtoupper(trim($rawSymbol));

        if (! preg_match('/^[A-Za-z0-9_.\-]{1,20}$/', $symbol)) {
            abort(404, 'Invalid instrument symbol.');
        }

        $actualType = $this->classifier->classify($symbol);
        $canonicalSymbol = strtolower($symbol);

        if ($expectedType === InstrumentClassifier::TYPE_STOCK && $actualType === InstrumentClassifier::TYPE_FUTURES) {
            $target = "/futures/{$canonicalSymbol}";
            if ($request->getQueryString()) {
                $target .= '?'.$request->getQueryString();
            }

            return redirect($target, 301);
        }

        if ($expectedType === InstrumentClassifier::TYPE_FUTURES && $actualType === InstrumentClassifier::TYPE_STOCK) {
            $target = "/stock/{$canonicalSymbol}";
            if ($request->getQueryString()) {
                $target .= '?'.$request->getQueryString();
            }

            return redirect($target, 301);
        }

        return $next($request);
    }
}
