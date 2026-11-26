<?php

namespace App\Application;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CustomerRosterExport
{
    public function download(User $user, Builder $query, string $format, string $source = 'complete', ?array $columns = null): StreamedResponse
    {
        Access::check($user, 'customers.read');
        Access::check($user, 'report.export');
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 422);
        $columns = TableExportColumns::resolve($user, 'customers', $columns);
        $locale = app()->getLocale();
        $headers = TableExportColumns::headers('customers', $columns, $locale);
        $query = clone $query;
        $snapshot = app(Customers::class)->snapshot($user, $source)['selected'];
        $complete = $snapshot?->status === 'complete';
        Audit::record((string) $user->id, 'customers.export', 'cohort', $snapshot?->id ?? '', ['format' => $format, 'customer_source' => $source, 'columns' => $columns]);

        return response()->streamDownload(function () use ($query, $headers, $columns, $locale, $complete, $format): void {
            $book = $format === 'xlsx' ? new Spreadsheet : null;
            $pdf = $format === 'pdf' ? new Mpdf(['tempDir' => storage_path('app/private/mpdf'), 'format' => 'A4-L', 'default_font' => 'dhyana', 'default_font_size' => 9]) : null;
            $sheet = $book?->getActiveSheet()->setTitle('Customers');
            $sheet?->fromArray($headers);
            $sheet?->freezePane('A2');
            if ($pdf) {
                $pdf->SetTitle('Easy Gold Customers');
                $pdf->SetHTMLFooter('<div style="text-align:right">Easy Gold · {PAGENO}/{nbpg}</div>');
            }
            $index = 2;
            $pdfRows = [];
            try {
                foreach ($query->orderBy('cohort_members.actor_user_id')->lazy(200) as $member) {
                    $row = TableExportColumns::customerRow($member, $columns, $index - 1, $locale, $complete);
                    if ($sheet) {
                        foreach ($row as $column => $value) {
                            $sheet->setCellValueExplicit([$column + 1, $index], $value ?? '', is_int($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                        }
                    }
                    if ($pdf) {
                        $pdfRows[] = $row;
                        if (count($pdfRows) === 50) {
                            $pdf->WriteHTML(view('exports.customers', ['headers' => $headers, 'rows' => $pdfRows])->render());
                            $pdfRows = [];
                        }
                    }
                    $index++;
                }
                if ($book) {
                    $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);
                    $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
                    $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setWrapText(true);
                    foreach ($sheet->getColumnIterator() as $column) {
                        $sheet->getColumnDimension($column->getColumnIndex())->setWidth(26);
                    }
                    (new Xlsx($book))->save('php://output');
                } else {
                    if ($pdfRows !== [] || $index === 2) {
                        $pdf->WriteHTML(view('exports.customers', ['headers' => $headers, 'rows' => $pdfRows])->render());
                    }
                    echo $pdf->Output('', Destination::STRING_RETURN);
                }
            } finally {
                $book?->disconnectWorksheets();
            }
        }, 'easy-gold-customers.'.$format, ['Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store']);
    }
}
