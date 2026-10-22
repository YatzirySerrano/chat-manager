<?php

namespace App\Http\Controllers\Exports;

use App\Enums\ExportFormat;
use App\Http\Controllers\Controller;
use App\Models\DataTransfer;
use App\Models\User;
use App\Reports\ReportRegistry;
use App\Services\Exports\ReportExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportExportController extends Controller
{
    /**
     * Download a report now, or queue it when it is large. The filters come
     * from the query string and are validated by the report definition.
     */
    public function __invoke(Request $request, string $report, string $format, ReportRegistry $registry, ReportExporter $exporter): BinaryFileResponse|RedirectResponse
    {
        $definition = $registry->find($report) ?? abort(404);
        $exportFormat = ExportFormat::from($format);

        /** @var User $actor */
        $actor = $request->user();

        $context = $exporter->prepare($definition, $actor, $request->query());

        try {
            $result = $exporter->export($definition, $context, $exportFormat);
        } catch (ValidationException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->validator->errors()->first()]);

            throw $exception;
        }

        if ($result instanceof DataTransfer) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => "Estamos generando tu archivo de {$definition->title()}. Te avisaremos en Exportaciones cuando esté listo.",
                'action' => ['label' => 'Ver exportaciones', 'href' => route('exports.index')],
            ]);

            return back();
        }

        return $result;
    }
}
