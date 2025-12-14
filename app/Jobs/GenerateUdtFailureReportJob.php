<?php

namespace App\Jobs;

use App\Models\UdtAttemptLog;
use App\Services\DiagnosticService;
use App\Services\DropboxService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateUdtFailureReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 120;

    protected string $attemptLogId;

    public function __construct(string $attemptLogId)
    {
        $this->attemptLogId = $attemptLogId;
    }

    public function handle(): void
    {
        $attemptLog = UdtAttemptLog::find($this->attemptLogId);

        if (!$attemptLog) {
            Log::warning("⚠️ No se encontró UdtAttemptLog con ID: {$this->attemptLogId}");
            return;
        }

        Log::info("📝 Generando reporte de fallo para persona {$attemptLog->person_id}", [
            'job_id' => $attemptLog->job_id,
            'total_attempts' => $attemptLog->attempt_number,
        ]);

        try {
            // Generate comprehensive failure report
            $report = $this->generateDetailedReport($attemptLog);

            // Upload to Dropbox
            $this->uploadReport($report, $attemptLog);

            Log::info("✅ Reporte de fallo generado y subido exitosamente", [
                'person_id' => $attemptLog->person_id,
                'job_id' => $attemptLog->job_id,
            ]);

        } catch (Throwable $e) {
            Log::error("❌ Error generando reporte de fallo", [
                'person_id' => $attemptLog->person_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function generateDetailedReport(UdtAttemptLog $attemptLog): array
    {
        // Get current diagnostics for the report
        $currentDiagnostics = DiagnosticService::collect();

        // Base report from model
        $report = $attemptLog->generateFailureReport();

        // Add enhanced analysis
        $report['analysis'] = $this->analyzeFailures($attemptLog);
        $report['recommendations'] = $this->generateRecommendations($attemptLog);
        $report['current_system_state'] = $currentDiagnostics;

        // Add timeline
        $report['timeline'] = $this->generateTimeline($attemptLog);

        // Add error frequency analysis
        $report['error_frequency'] = $this->analyzeErrorFrequency($attemptLog);

        return $report;
    }

    protected function analyzeFailures(UdtAttemptLog $attemptLog): array
    {
        $history = $attemptLog->attempts_history ?? [];
        
        $analysis = [
            'total_attempts' => count($history),
            'total_duration_hours' => 0,
            'error_types_count' => [],
            'steps_failed' => [],
            'network_issues_detected' => false,
            'website_issues_detected' => false,
            'authentication_issues_detected' => false,
        ];

        if ($attemptLog->started_at && $attemptLog->finished_at) {
            $analysis['total_duration_hours'] = $attemptLog->started_at->diffInHours($attemptLog->finished_at);
        }

        foreach ($history as $attempt) {
            if (isset($attempt['error']['type'])) {
                $type = $attempt['error']['type'];
                $analysis['error_types_count'][$type] = ($analysis['error_types_count'][$type] ?? 0) + 1;

                if (in_array($type, ['timeout', 'network'])) {
                    $analysis['network_issues_detected'] = true;
                }
                if ($type === 'website_down') {
                    $analysis['website_issues_detected'] = true;
                }
                if ($type === 'authentication') {
                    $analysis['authentication_issues_detected'] = true;
                }
            }

            if (isset($attempt['step'])) {
                $step = $attempt['step'];
                $analysis['steps_failed'][$step] = ($analysis['steps_failed'][$step] ?? 0) + 1;
            }
        }

        // Determine primary failure cause
        if (!empty($analysis['error_types_count'])) {
            $analysis['primary_failure_cause'] = array_search(
                max($analysis['error_types_count']),
                $analysis['error_types_count']
            );
        }

        return $analysis;
    }

    protected function generateRecommendations(UdtAttemptLog $attemptLog): array
    {
        $recommendations = [];
        $analysis = $this->analyzeFailures($attemptLog);

        if ($analysis['network_issues_detected']) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'network',
                'message' => 'Se detectaron problemas de red frecuentes. Verificar la conectividad del servidor y considerar aumentar los timeouts.',
                'action' => 'Revisar logs de red y verificar estabilidad de conexión a Internet.',
            ];
        }

        if ($analysis['website_issues_detected']) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'external',
                'message' => 'El sitio web de BPS experimentó problemas de disponibilidad durante el procesamiento.',
                'action' => 'Contactar al equipo técnico de BPS si el problema persiste. Considerar horarios alternativos de procesamiento.',
            ];
        }

        if ($analysis['authentication_issues_detected']) {
            $recommendations[] = [
                'priority' => 'critical',
                'category' => 'credentials',
                'message' => 'Se detectaron errores de autenticación. Las credenciales pueden estar incorrectas o expiradas.',
                'action' => 'Verificar y actualizar las credenciales de la empresa en el sistema.',
            ];
        }

        if (isset($analysis['steps_failed']['element_not_found']) && $analysis['steps_failed']['element_not_found'] > 2) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'ui_changes',
                'message' => 'El sitio web puede haber cambiado su estructura. Los selectores del scraper podrían necesitar actualización.',
                'action' => 'Revisar los selectores CSS/XPath en el script de Puppeteer.',
            ];
        }

        if (empty($recommendations)) {
            $recommendations[] = [
                'priority' => 'low',
                'category' => 'general',
                'message' => 'No se identificó una causa específica clara. Se recomienda revisar los logs detallados.',
                'action' => 'Revisar el historial completo de intentos y los diagnósticos del sistema.',
            ];
        }

        return $recommendations;
    }

    protected function generateTimeline(UdtAttemptLog $attemptLog): array
    {
        $timeline = [];
        $history = $attemptLog->attempts_history ?? [];

        foreach ($history as $index => $attempt) {
            $timeline[] = [
                'attempt' => $index + 1,
                'timestamp' => $attempt['attempted_at'] ?? null,
                'status' => $attempt['status'] ?? 'unknown',
                'step' => $attempt['step'] ?? 'unknown',
                'error_type' => $attempt['error']['type'] ?? null,
                'error_message' => isset($attempt['error']['message']) 
                    ? substr($attempt['error']['message'], 0, 200) 
                    : null,
                'network_status' => [
                    'internet' => $attempt['diagnostics']['network']['internet_reachable'] ?? null,
                    'bps_reachable' => $attempt['diagnostics']['network']['bps_website_reachable'] ?? null,
                    'latency_ms' => $attempt['diagnostics']['network']['bps_latency_ms'] ?? null,
                ],
            ];
        }

        return $timeline;
    }

    protected function analyzeErrorFrequency(UdtAttemptLog $attemptLog): array
    {
        $history = $attemptLog->attempts_history ?? [];
        $frequency = [];

        foreach ($history as $attempt) {
            if (!isset($attempt['attempted_at'])) continue;

            $hour = date('H', strtotime($attempt['attempted_at']));
            $frequency[$hour] = ($frequency[$hour] ?? 0) + 1;
        }

        ksort($frequency);

        return [
            'by_hour' => $frequency,
            'peak_failure_hour' => !empty($frequency) ? array_search(max($frequency), $frequency) : null,
        ];
    }

    protected function uploadReport(array $report, UdtAttemptLog $attemptLog): void
    {
        $dropbox = DropboxService::getDisk();

        $date = now()->format('Y-m-d_H-i-s');
        $fileName = "failure_report_{$attemptLog->person_id}_{$date}.json";
        $path = "web/failure_reports/{$attemptLog->company_name}/{$fileName}";

        // Upload JSON report
        $dropbox->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Also create a human-readable summary
        $summaryFileName = "failure_summary_{$attemptLog->person_id}_{$date}.txt";
        $summaryPath = "web/failure_reports/{$attemptLog->company_name}/{$summaryFileName}";
        
        $summary = $this->generateHumanReadableSummary($report, $attemptLog);
        $dropbox->put($summaryPath, $summary);

        Log::info("📤 Reportes subidos a Dropbox", [
            'json_report' => $path,
            'summary' => $summaryPath,
        ]);
    }

    protected function generateHumanReadableSummary(array $report, UdtAttemptLog $attemptLog): string
    {
        $summary = [];
        $summary[] = "═══════════════════════════════════════════════════════════════";
        $summary[] = "           REPORTE DE FALLO UDT - " . now()->format('d/m/Y H:i:s');
        $summary[] = "═══════════════════════════════════════════════════════════════";
        $summary[] = "";
        
        $summary[] = "📋 INFORMACIÓN GENERAL";
        $summary[] = "───────────────────────────────────────────────────────────────";
        $summary[] = "Archivo:        {$attemptLog->file_name}";
        $summary[] = "Persona ID:     {$attemptLog->person_id}";
        $summary[] = "CI:             {$attemptLog->person_ci}";
        $summary[] = "Nombre:         {$attemptLog->person_name}";
        $summary[] = "Empresa:        {$attemptLog->company_name} ({$attemptLog->company_number})";
        $summary[] = "";

        $summary[] = "📊 ESTADÍSTICAS DE INTENTOS";
        $summary[] = "───────────────────────────────────────────────────────────────";
        $summary[] = "Total intentos:     {$attemptLog->attempt_number}";
        $summary[] = "Máximo permitido:   {$attemptLog->max_attempts}";
        $summary[] = "Primer intento:     " . ($attemptLog->started_at?->format('d/m/Y H:i:s') ?? 'N/A');
        $summary[] = "Último intento:     " . ($attemptLog->finished_at?->format('d/m/Y H:i:s') ?? 'N/A');
        $summary[] = "Duración total:     " . ($report['summary']['total_duration_hours'] ?? 0) . " horas";
        $summary[] = "";

        $summary[] = "❌ ÚLTIMO ERROR";
        $summary[] = "───────────────────────────────────────────────────────────────";
        $summary[] = "Tipo:           {$attemptLog->error_type}";
        $summary[] = "Paso:           {$attemptLog->error_step}";
        $summary[] = "Mensaje:        {$attemptLog->error_message}";
        $summary[] = "";

        if (!empty($report['analysis']['error_types_count'])) {
            $summary[] = "📈 FRECUENCIA DE ERRORES";
            $summary[] = "───────────────────────────────────────────────────────────────";
            foreach ($report['analysis']['error_types_count'] as $type => $count) {
                $summary[] = "  - {$type}: {$count} veces";
            }
            $summary[] = "";
        }

        if (!empty($report['recommendations'])) {
            $summary[] = "💡 RECOMENDACIONES";
            $summary[] = "───────────────────────────────────────────────────────────────";
            foreach ($report['recommendations'] as $rec) {
                $summary[] = "[{$rec['priority']}] {$rec['message']}";
                $summary[] = "   → {$rec['action']}";
                $summary[] = "";
            }
        }

        $summary[] = "🌐 ESTADO DE RED (ÚLTIMO DIAGNÓSTICO)";
        $summary[] = "───────────────────────────────────────────────────────────────";
        $network = $report['current_system_state']['network'] ?? [];
        $summary[] = "Internet:       " . ($network['internet_reachable'] ?? false ? '✅ OK' : '❌ FALLO');
        $summary[] = "BPS Website:    " . ($network['bps_website_reachable'] ?? false ? '✅ OK' : '❌ FALLO');
        $summary[] = "Latencia BPS:   " . ($network['bps_latency_ms'] ?? 'N/A') . " ms";
        $summary[] = "Velocidad:      " . ($network['download_speed_mbps'] ?? 'N/A') . " Mbps";
        $summary[] = "";

        $summary[] = "═══════════════════════════════════════════════════════════════";
        $summary[] = "  Para más detalles, consulte el archivo JSON adjunto.";
        $summary[] = "═══════════════════════════════════════════════════════════════";

        return implode("\n", $summary);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error("❌ GenerateUdtFailureReportJob falló", [
            'attempt_log_id' => $this->attemptLogId,
            'error' => $exception?->getMessage(),
        ]);
    }
}

