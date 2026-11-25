<?php

namespace App\Application;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CustomerRosterExport
{
    public function download(User $user, Builder $query, string $format, string $source = 'complete'): StreamedResponse
    {
        Access::check($user, 'customers.read');
        Access::check($user, 'report.export');
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 422);
        $identity = $user->can('customer_identity.read');
        $answers = $user->can('feedback.read');
        $headers = [__('feedback.customer_user_id')];
        if ($identity) {
            $headers = [...$headers, __('feedback.name'), __('feedback.customer')];
        }
        $headers = [...$headers, __('feedback.response_status'), __('feedback.responses'), __('feedback.customer_latest_response')];
        if ($answers) {
            $headers = [...$headers, __('feedback.survey'), __('feedback.rating'), __('feedback.customer_latest_feedback')];
        }
        $query = clone $query;
        $snapshot = app(Customers::class)->snapshot($user, $source)['selected'];
        $complete = $snapshot?->status === 'complete';
        Audit::record((string) $user->id, 'customers.export', 'cohort', $snapshot?->id ?? '', ['format' => $format, 'customer_source' => $source]);

        return response()->streamDownload(function () use ($query, $headers, $identity, $answers, $complete, $format): void {
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
            try {
                foreach ($query->orderBy('cohort_members.actor_user_id')->lazy(200) as $member) {
                    $status = ! $complete || ! $member->identity_complete ? 'incomplete' : ($member->response_count > 0 ? 'answered' : 'unanswered');
                    $row = [$member->actor_user_id];
                    if ($identity) {
                        $row = [...$row, $member->display_name, $member->phone];
                    }
                    $row = [...$row, __('feedback.'.$status), (string) $member->response_count, $member->latest_at ? CarbonImmutable::parse($member->latest_at, 'UTC')->setTimezone('Asia/Vientiane')->format('d/m/Y H:i') : '—'];
                    if ($answers) {
                        $response = $member->latestFilteredResponse;
                        $labels = $response?->answers->flatMap(fn ($answer) => collect([$answer->text_value])->filter()->merge($answer->options->map(fn ($option) => $option->label[app()->getLocale()] ?? $option->label['en'])))->implode("\n");
                        $row = [...$row, $response?->version?->survey?->name, $response?->answers->firstWhere('rating_value', '!=', null)?->rating_value, $labels];
                    }
                    if ($sheet) {
                        foreach ($row as $column => $value) {
                            $sheet->setCellValueExplicit([$column + 1, $index], (string) ($value ?? ''), DataType::TYPE_STRING);
                        }
                    }
                    if ($pdf) {
                        $pdf->WriteHTML(view('exports.customers', ['headers' => $headers, 'row' => $row])->render());
                    }
                    $index++;
                }
                if ($book) {
                    $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);
                    $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
                    (new Xlsx($book))->save('php://output');
                } else {
                    if ($index === 2) {
                        $pdf->WriteHTML(view('exports.customers', ['headers' => $headers, 'row' => []])->render());
                    }
                    echo $pdf->Output('', Destination::STRING_RETURN);
                }
            } finally {
                $book?->disconnectWorksheets();
            }
        }, 'easy-gold-customers.'.$format, ['Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store']);
    }
}
