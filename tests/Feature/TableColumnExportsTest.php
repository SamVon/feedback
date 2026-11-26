<?php

namespace Tests\Feature;

use App\Application\CustomerRosterExport;
use App\Application\ExportRenderer;
use App\Application\Exports;
use App\Filament\Pages\Customers;
use App\Filament\Resources\Pages\ListFeedback;
use App\Jobs\BuildFeedbackExport;
use App\Models\ExportJob;
use App\Models\FeedbackResponse;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class TableColumnExportsTest extends FeedbackTest
{
    private function responses(): array
    {
        $this->freezeTime();
        $payload = $this->payload('columns-first', 'columns-a');
        $payload['answers'][] = ['question_id' => $this->survey->questions()->where('type', 'text')->firstOrFail()->id, 'text_value' => '<script>private-comment</script>'];
        $payload['answers'][] = ['question_id' => $this->survey->questions()->where('type', 'choice')->firstOrFail()->id,
            'option_ids' => [$this->survey->questions()->where('type', 'choice')->firstOrFail()->options()->firstOrFail()->id]];
        $first = FeedbackResponse::findOrFail($this->api($payload, 'columns-first')->assertCreated()->json('response_id'));
        $first->update(['customer_name' => '=Private formula name', 'customer_phone' => '02000123456']);
        $this->travel(1)->minute();
        $second = FeedbackResponse::findOrFail($this->api($this->payload('columns-second', 'columns-b'), 'columns-second')->assertCreated()->json('response_id'));
        $second->update(['customer_name' => 'Newest private name', 'customer_phone' => '02000123456']);
        $this->actingAs($this->staff);

        return [$first, $second];
    }

    private function workbook(string $bytes): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'column-export-');
        try {
            file_put_contents($path, $bytes);

            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }

    public function test_table_export_feedback_excel_matches_visible_headers_and_groups_answers_into_one_row(): void
    {
        [$first, $second] = $this->responses();
        Queue::fake();
        $page = Livewire::test(ListFeedback::class)->set('tableSearch', '02000123456');
        $headers = array_map(fn ($column): string => (string) $column->getLabel(), array_values($page->instance()->getTable()->getVisibleColumns()));
        $page->call('exportHistory', 'xlsx')->assertNotified();
        $job = ExportJob::firstOrFail();
        $this->assertNotContains('actor_user_id', $job->table_layout['columns']);
        (new BuildFeedbackExport($job->id))->handle();
        $book = $this->workbook(Storage::disk('local')->get($job->fresh()->file_ref));
        try {
            $sheet = $book->getSheetByName('Responses');
            $this->assertSame($headers, $sheet->rangeToArray('A1:I1')[0]);
            $this->assertSame(3, $sheet->getHighestRow());
            $this->assertSame('Newest private name', $sheet->getCell('D2')->getValue());
            $this->assertSame('=Private formula name', $sheet->getCell('D3')->getValue());
            $this->assertSame('s', $sheet->getCell('D3')->getDataType());
            $this->assertSame('02000123456', $sheet->getCell('C3')->getValue());
            $this->assertSame('s', $sheet->getCell('C3')->getDataType());
            $this->assertSame('5 / 5', $sheet->getCell('G3')->getValue());
            $this->assertSame('Speed', $sheet->getCell('H3')->getValue());
            $this->assertSame('<script>private-comment</script>', $sheet->getCell('I3')->getValue());
            $this->assertStringContainsString('Overall', $sheet->getCell('F3')->getValue());
            $this->assertStringContainsString('Comment', $sheet->getCell('F3')->getValue());
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_table_export_feedback_snapshots_enabled_columns_order_sort_and_pdf_layout(): void
    {
        [$first] = $this->responses();
        Queue::fake();
        $page = Livewire::test(ListFeedback::class);
        $state = array_map(function (array $column): array {
            if ($column['name'] === 'actor_user_id') {
                $column['isToggled'] = true;
            }

            return $column;
        }, $page->get('tableColumns'));
        $page->call('applyTableColumnManager', $state)->sortTable('received_at', 'asc')->call('exportHistory', 'pdf')->assertNotified();
        $job = ExportJob::firstOrFail();
        $this->assertSame('actor_user_id', $job->table_layout['columns'][3]);
        $this->assertSame('asc', $job->table_layout['sort_direction']);
        $page->call('applyTableColumnManager', $page->instance()->getDefaultTableColumnState());
        $renderer = app(ExportRenderer::class);
        $rows = iterator_to_array($renderer->rows($job));
        $this->assertSame('columns-a', $rows[0][3]);
        $this->assertSame(2, count($rows));
        $html = view('exports.customers', ['headers' => $renderer->headers($job), 'rows' => $rows])->render();
        $this->assertStringContainsString(__('feedback.customer_user_id'), $html);
        $this->assertStringContainsString('&lt;script&gt;private-comment&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        (new BuildFeedbackExport($job->id))->handle();
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($job->fresh()->file_ref));
    }

    public function test_table_export_customer_headers_values_and_hidden_user_id_match_the_table(): void
    {
        [$first, $second] = $this->responses();
        $first->update(['source' => 'auto_prompt']);
        $second->update(['source' => 'auto_prompt']);
        $page = Livewire::test(Customers::class)->set('tableSearch', '02000123456');
        $columns = array_keys($page->instance()->getTable()->getVisibleColumns());
        $headers = array_map(fn ($column): string => (string) $column->getLabel(), array_values($page->instance()->getTable()->getVisibleColumns()));
        $this->assertNotContains('actor_user_id', $columns);
        $page->call('exportRoster', 'xlsx')->assertFileDownloaded('easy-gold-customers.xlsx');
        $response = app(CustomerRosterExport::class)->download($this->staff, $page->instance()->getFilteredSortedTableQuery(), 'xlsx', columns: $columns);
        ob_start();
        $response->sendContent();
        $book = $this->workbook(ob_get_clean());
        try {
            $sheet = $book->getActiveSheet();
            $this->assertSame($headers, $sheet->rangeToArray('A1:J1')[0]);
            $this->assertSame(3, $sheet->getHighestRow());
            $this->assertSame('02000123456', $sheet->getCell('B2')->getValue());
            $this->assertSame('=Private formula name', $sheet->getCell('C2')->getValue());
            $this->assertSame('s', $sheet->getCell('C2')->getDataType());
            $this->assertSame('02000123456', $sheet->getCell('D2')->getValue());
            $this->assertSame('5 / 5', $sheet->getCell('I2')->getValue());
            $this->assertStringContainsString('private-comment', $sheet->getCell('J2')->getValue());
        } finally {
            $book->disconnectWorksheets();
        }
        $page->call('exportRoster', 'pdf')->assertFileDownloaded('easy-gold-customers.pdf');
    }

    public function test_table_export_identity_is_excluded_without_permission_and_revocation_blocks_download(): void
    {
        $this->responses();
        Queue::fake();
        $page = Livewire::test(ListFeedback::class)->call('exportHistory', 'xlsx');
        $privateJob = ExportJob::firstOrFail();
        (new BuildFeedbackExport($privateJob->id))->handle();
        $this->staff->update(['permissions' => array_values(array_diff(config('feedback.permissions'), ['customer_identity.read']))]);
        $this->actingAs($this->staff->fresh())->get(route('exports.download', $privateJob))->assertForbidden();
        $viewer = User::factory()->create(['active' => true, 'permissions' => ['feedback.read', 'report.export']]);
        $this->actingAs($viewer);
        Livewire::test(ListFeedback::class)->call('exportHistory', 'xlsx')->assertNotified();
        $publicJob = ExportJob::where('user_id', $viewer->id)->firstOrFail();
        $this->assertSame(['number', 'received_at', 'question', 'rating', 'selected_answers', 'feedback_text'], $publicJob->table_layout['columns']);
        (new BuildFeedbackExport($publicJob->id))->handle();
        $rows = iterator_to_array(app(ExportRenderer::class)->rows($publicJob));
        $this->assertStringNotContainsString('Private formula name', json_encode($rows));
        $this->assertStringNotContainsString('02000123456', json_encode($rows));
        $this->expectException(AuthorizationException::class);
        app(Exports::class)->create($viewer, [], 'xlsx', tableLayout: ['columns' => ['customer_phone']]);
    }
}
