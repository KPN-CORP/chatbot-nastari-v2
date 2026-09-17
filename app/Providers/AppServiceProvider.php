<?php

namespace App\Providers;

use App\Services\NastariActivityLogger;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One logger per request/command, so a single correlation_id ties
        // together every event produced while handling one WhatsApp message.
        $this->app->singleton(NastariActivityLogger::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        $this->instrumentOutboundHttp();
    }

    /**
     * Adds timeouts and failure/latency recording to every outbound HTTP call.
     *
     * Done globally rather than at the call sites on purpose: there are more
     * than twenty Http:: calls spread across DarwinboxService, HearService,
     * RuangService and WhatsAppSenderService, none of which set a timeout.
     * Editing each one would be both risky and easy to forget on the next call
     * added. Guzzle's on_stats callback fires for successes and failures alike
     * and carries the real transfer time.
     *
     * Not covered: WhatsAppSenderService::uploadMediaToWhatsApp uses raw cURL
     * rather than the HTTP client, so it is instrumented at its own call site.
     */
    private function instrumentOutboundHttp(): void
    {
        $config = (array) config('nastari.logging.http', []);
        $slowMs = (int) ($config['slow_ms'] ?? 5000);

        Http::globalOptions(function () use ($config, $slowMs) {
            return [
                'timeout'         => (float) ($config['timeout'] ?? 20),
                'connect_timeout' => (float) ($config['connect_timeout'] ?? 5),

                'on_stats' => function (TransferStats $stats) use ($slowMs) {
                    try {
                        $this->recordTransfer($stats, $slowMs);
                    } catch (\Throwable) {
                        // Instrumentation must never break the actual call.
                    }
                },
            ];
        });
    }

    private function recordTransfer(TransferStats $stats, int $slowMs): void
    {
        if (! config('nastari.logging.enabled', true)) {
            return;
        }

        $uri = $stats->getEffectiveUri();
        $service = $this->serviceFor($uri->getHost() . ($uri->getPort() ? ':' . $uri->getPort() : ''));

        if ($service === null) {
            return; // Not one of our known dependencies; nothing to attribute.
        }

        // Method + path only: Darwinbox query strings can carry api_key values.
        $endpoint = strtoupper($stats->getRequest()->getMethod()) . ' ' . ($uri->getPath() ?: '/');
        $durationMs = (int) round(($stats->getTransferTime() ?? 0) * 1000);

        $logger = $this->app->make(NastariActivityLogger::class);
        $response = $stats->getResponse();

        if ($response === null) {
            // No response at all: connection refused, DNS failure or timeout.
            $handlerError = (string) ($stats->getHandlerErrorData() ?? '');
            $errorType = Str::contains(strtolower($handlerError), ['timed out', 'timeout'])
                ? 'timeout'
                : 'connection';

            $logger->externalFailure($service, $endpoint, $errorType, [
                'duration_ms' => $durationMs,
                'payload'     => ['detail' => Str::limit($handlerError, 180, '')],
            ]);

            return;
        }

        $status = $response->getStatusCode();

        if ($status >= 500) {
            $logger->externalFailure($service, $endpoint, 'unavailable', [
                'duration_ms' => $durationMs,
                'http_status' => $status,
            ]);

            return;
        }

        if ($status >= 400) {
            $logger->externalFailure($service, $endpoint, 'http_error', [
                'duration_ms' => $durationMs,
                'http_status' => $status,
            ]);

            return;
        }

        // Successful but slow calls are the early warning for the timeouts
        // that follow, so they are worth a row of their own.
        if ($durationMs >= $slowMs) {
            $logger->record('external_service.slow', [
                'service'       => $service,
                'endpoint'      => $endpoint,
                'error_type'    => 'slow',
                'outcome'       => 'success',
                'duration_ms'   => $durationMs,
                'http_status'   => $status,
                'feature_label' => 'Layanan eksternal lambat',
            ]);
        }
    }

    private function serviceFor(string $hostWithPort): ?string
    {
        foreach ((array) config('nastari.logging.services', []) as $fragment => $name) {
            if ($fragment !== '' && str_contains($hostWithPort, (string) $fragment)) {
                return (string) $name;
            }
        }

        return null;
    }
}
