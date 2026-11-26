<table style="width:100%; border-collapse:collapse; margin-bottom:6px">
    <thead><tr>@foreach ($headers as $label)<th style="border:1px solid #ddd; padding:6px; text-align:left; background:#f5f6f7">{{ $label }}</th>@endforeach</tr></thead>
    <tbody>
        @foreach ($rows as $row)
            <tr>@foreach ($row as $value)<td style="border:1px solid #ddd; padding:6px; vertical-align:top">{!! nl2br(e((string) ($value ?? ''))) !!}</td>@endforeach</tr>
        @endforeach
    </tbody>
</table>
