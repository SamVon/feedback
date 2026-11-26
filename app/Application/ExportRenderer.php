<?php

namespace App\Application;

use App\Models\ExportJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ExportRenderer
{
    public const HEADERS = ['response_id', 'actor_user_id', 'survey_version_id', 'question_id', 'source', 'transaction_type', 'received_at_utc', 'received_at_vientiane', 'rating', 'text', 'options', 'question', 'selected_labels'];

    public static function bar(float $percentage): string
    {
        $image = imagecreatetruecolor(260, 12);
        imagefill($image, 0, 0, imagecolorallocate($image, 240, 235, 235));
        $width = (int) round(260 * max(0, min(100, $percentage)) / 100);
        if ($width > 0) {
            imagefilledrectangle($image, 0, 0, $width - 1, 11, imagecolorallocate($image, 176, 51, 51));
        }
        ob_start();
        imagepng($image);
        $png = ob_get_clean();

        return 'data:image/png;base64,'.base64_encode($png);
    }

    public function rows(ExportJob $job): \Generator
    {
        $members = DB::table('export_job_responses')->where('export_job_id', $job->id)->select('feedback_response_id');
        if ($job->table_layout !== null) {
            $columns = TableExportColumns::resolve(User::findOrFail($job->user_id), 'feedback', $job->table_layout['columns']);
            $direction = $job->table_layout['sort_direction'];
            abort_unless(in_array($direction, ['asc', 'desc'], true), 422);
            $number = 0;
            foreach (ReportFilters::query($job->filters)->whereIn('id', $members)->with('answers.options', 'answers.question', 'context', 'version.survey')
                ->orderBy('received_at', $direction)->orderBy('id', $direction)->lazy(200) as $response) {
                yield TableExportColumns::feedbackRow($response, $columns, ++$number, $job->locale);
            }

            return;
        }
        foreach (ReportFilters::query($job->filters)->whereIn('id', $members)->with('answers.options', 'answers.question', 'context')->lazyById(200) as $response) {
            foreach ($response->answers as $answer) {
                if (! empty($job->filters['question_id']) && $answer->question_id !== $job->filters['question_id']) {
                    continue;
                }
                yield [$response->id, $response->actor_user_id, $response->survey_version_id, $answer->question_id, $response->source, $response->context?->transaction_type, $response->received_at->utc()->toIso8601String(), $response->received_at->setTimezone('Asia/Vientiane')->toIso8601String(), $answer->rating_value, $answer->text_value, $answer->options->pluck('code')->join('|'), $answer->question->label[$job->locale] ?? $answer->question->label['en'], $answer->options->map(fn ($o) => $o->label[$job->locale] ?? $o->label['en'])->join(' | ')];
            }
        }
    }

    public function headers(ExportJob $job): array
    {
        if ($job->table_layout === null) {
            return self::HEADERS;
        }
        $columns = TableExportColumns::resolve(User::findOrFail($job->user_id), 'feedback', $job->table_layout['columns']);

        return TableExportColumns::headers('feedback', $columns, $job->locale);
    }

    public function write(ExportJob $job, string $path): void
    {
        if ($job->format === 'xlsx') {
            $this->excel($job, $path);
        } elseif ($job->format === 'pdf') {
            $this->pdf($job, $path);
        } else {
            $stream = fopen($path, 'wb');
            try {
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, $this->headers($job), ',', '"', '');
                foreach ($this->rows($job) as $row) {
                    fputcsv($stream, array_map([Exports::class, 'safeCell'], $row), ',', '"', '');
                }
            } finally {
                fclose($stream);
            }
        }
    }

    private function excel(ExportJob $job, string $path): void
    {
        $book = new Spreadsheet;
        try {
            $sheet = $book->getActiveSheet()->setTitle('Responses');
            $headers = $this->headers($job);
            $sheet->fromArray($headers);
            $index = 2;
            foreach ($this->rows($job) as $row) {
                foreach ($row as $column => $value) {
                    // User-controlled values are always literal strings, never Excel formulas.
                    $numeric = $job->table_layout !== null ? is_int($value) || is_float($value) : $column === 8 && $value !== null;
                    $sheet->setCellValueExplicit([$column + 1, $index], $value ?? '', $numeric ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                }
                $index++;
            }
            $sheet->freezePane('A2');
            $last = Coordinate::stringFromColumnIndex(count($headers));
            $sheet->setAutoFilter('A1:'.$last.max(1, $index - 1));
            $sheet->getStyle('A1:'.$last.'1')->getFont()->setBold(true);
            $sheet->getStyle('A1:'.$last.max(1, $index - 1))->getAlignment()->setWrapText(true);
            foreach (range(1, count($headers)) as $column) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth(28);
            }
            $meta = $book->createSheet()->setTitle('Summary');
            $lines = [['Easy Gold Feedback', $job->snapshot_at->toIso8601String()], ['Timezone', 'Asia/Vientiane'], ['Time basis', 'received_at']];
            foreach ($job->filters as $key => $value) {
                $lines[] = [$key, (string) $value];
            }
            if ($job->summary !== null) {
                foreach (['response_count', 'unique_customers', 'rating_count', 'average', 'csat'] as $key) {
                    $lines[] = [$key, $job->summary[$key] ?? __('feedback.no_data', [], $job->locale)];
                }
                $lines[] = ['Rating', 'Count'];
                foreach ($job->summary['distribution'] as $rating => $count) {
                    $lines[] = [$rating, $count];
                }
                $lines[] = ['Date', 'Rating responses', 'Average'];
                foreach ($job->summary['trend'] as $day) {
                    $lines[] = [$day['date'], $day['count'], $day['average']];
                }
                $lines[] = ['Choice', 'Count', 'Denominator', 'Percent'];
                foreach ($job->summary['choices'] as $choice) {
                    $lines[] = [$choice['label'], $choice['count'], $choice['denominator'], $choice['percent'] ?? __('feedback.no_data', [], $job->locale)];
                }
                $lines[] = [__('feedback.choice_denominator_hint', [], $job->locale)];
            }
            foreach ($lines as $row => $values) {
                foreach ($values as $column => $value) {
                    $meta->setCellValueExplicit([$column + 1, $row + 1], $value, is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                }
            }
            $meta->getColumnDimension('A')->setWidth(50);
            $meta->getColumnDimension('B')->setWidth(48);
            $meta->getStyle('A1:D'.count($lines))->getAlignment()->setWrapText(true);
            (new Xlsx($book))->save($path);
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function pdf(ExportJob $job, string $path): void
    {
        $headers = $this->headers($job);
        $pdf = new Mpdf(['tempDir' => storage_path('app/private/mpdf'), 'format' => 'A4-L', 'default_font' => 'dhyana', 'default_font_size' => 9]);
        $pdf->SetTitle('Easy Gold Feedback');
        $pdf->SetHTMLFooter('<div style="text-align:right">Easy Gold · {PAGENO} / {nbpg}</div>');
        $pdf->WriteHTML(view('exports.report', ['job' => $job, 'rows' => [], 'intro' => true])->render());
        $written = false;
        foreach (LazyCollection::make(fn () => $this->rows($job))->chunk(50) as $rows) {
            $written = true;
            $pdf->WriteHTML($job->table_layout !== null
                ? view('exports.customers', ['headers' => $headers, 'rows' => $rows])->render()
                : view('exports.report', ['job' => $job, 'rows' => $rows, 'intro' => false])->render());
        }
        if (! $written && $job->table_layout !== null) {
            $pdf->WriteHTML(view('exports.customers', ['headers' => $headers, 'rows' => []])->render());
        }
        $pdf->Output($path, Destination::FILE);
    }
}
