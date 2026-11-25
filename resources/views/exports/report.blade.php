@php($t = fn ($key) => __('feedback.'.$key, [], $job->locale))
@if($intro)
<style>
body { color: #263238; font-family: dhyana; } h1 { color: #9e3030; font-size: 22pt; } h2 { color: #9e3030; font-size: 13pt; } table { border-collapse: collapse; width: 100%; margin: 10px 0; } td, th { padding: 6px; border-bottom: 1px solid #ddd; vertical-align: top; } th { background: #f4eeee; text-align: left; } .muted { color: #667; font-size: 8pt; } .metric { font-size: 16pt; font-weight: bold; } .bar { background: #b03333; height: 10px; }
</style>
<h1>EASY GOLD · {{ $t('reports') }}</h1>
<p>{{ $t('snapshot_at') }}: {{ $job->snapshot_at->setTimezone('Asia/Vientiane')->format('d/m/Y H:i:s') }} · Asia/Vientiane · received_at</p>
<p class="muted">@foreach(\Illuminate\Support\Arr::except($job->filters, ['exclude_demo', 'default_rating_question']) as $key => $value) {{ $key }}: {{ $value }} · @endforeach</p>
@if($job->summary)
@php($m = $job->summary)
@isset($m['rating_question_label'])<p>{{ $m['rating_question_survey'] }} · {{ $m['rating_question_label'] }}</p>@endisset
<table><tr><th>{{ $t('average') }}</th><th>CSAT</th><th>{{ $t('responses') }}</th><th>{{ $t('unique_customers') }}</th></tr><tr>
<td class="metric">{{ $m['average'] ?? $t('no_data') }}</td><td class="metric">{{ $m['csat'] === null ? $t('no_data') : $m['csat'].'%' }}</td><td class="metric">{{ $m['response_count'] }}</td><td class="metric">{{ $m['unique_customers'] }}</td></tr></table>
<p>{{ $t('csat_hint') }} · {{ $t('ratings') }}: {{ $m['rating_count'] }}</p>
<h2>{{ $t('distribution') }}</h2>
@if($m['rating_count'])
<table><tr><th>{{ $t('rating') }}</th><th>{{ $t('responses') }}</th><th>%</th><th></th></tr>
@foreach(array_reverse($m['distribution'], true) as $rating => $count)
<tr><td>{{ $rating }} / 5 {{ $rating == 3 ? ' · '.$t('neutral') : '' }}</td><td>{{ $count }}</td><td>{{ round(100 * $count / $m['rating_count'], 2) }}%</td><td style="width:50%"><img width="260" height="12" src="{{ \App\Application\ExportRenderer::bar(100 * $count / $m['rating_count']) }}"></td></tr>
@endforeach</table>
@else<p>{{ $t('no_data') }}</p>@endif
@if($m['trend'])
<h2>{{ $t('trend') }}</h2><table><tr><th>{{ $t('received_at') }}</th><th>{{ $t('ratings') }}</th><th>{{ $t('average') }}</th></tr>
@foreach($m['trend'] as $day)<tr><td>{{ $day['date'] }}</td><td>{{ $day['count'] }}</td><td>{{ $day['average'] }}</td></tr>@endforeach</table>
@endif
@if($m['choices'])
<h2>{{ $t('choices') }}</h2><p>{{ $t('choice_denominator_hint') }}</p><table><tr><th>{{ $t('options') }}</th><th>{{ $t('responses') }}</th><th>n</th><th>%</th></tr>
@foreach($m['choices'] as $choice)<tr><td>{{ $choice['label'] }}</td><td>{{ $choice['count'] }}</td><td>{{ $choice['denominator'] }}</td><td>{{ $choice['percent'] ?? $t('no_data') }}</td></tr>@endforeach</table>
@endif
@endif
<h2>{{ $t('feedback') }}</h2>
@else
<table><thead><tr><th>{{ $t('received_at') }}</th><th>{{ $t('customer') }}</th><th>{{ $t('source') }} / {{ $t('transaction_type') }}</th><th>{{ $t('question') }}</th><th>{{ $t('rating') }}</th><th>{{ $t('answers') }}</th></tr></thead><tbody>
@foreach($rows as $row)
<tr><td>{{ $row[7] }}</td><td>{{ $row[1] }}</td><td>{{ $row[4] }}<br>{{ $row[5] ?? '—' }}</td><td>{{ $row[11] }}</td><td>{{ $row[8] ?? '—' }}</td><td>{{ $row[12] }}<br>{{ $row[9] }}</td></tr>
@endforeach</tbody></table>
@endif
