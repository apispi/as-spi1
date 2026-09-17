@component('mail::message')
@if($recovered)
# {{ $monitorName }} has recovered

Good news — **{{ $monitorName }}** is passing its checks again.

{{ $passedCount }} of {{ $total }} steps passed in {{ $timeMs }} ms.
@else
# {{ $monitorName }} is failing

**{{ $monitorName }}** failed its checks.

@if($summary)
> {{ $summary }}
@endif

{{ $passedCount }} of {{ $total }} steps passed in {{ $timeMs }} ms@if($consecutiveFailures > 1), and it has now failed {{ $consecutiveFailures }} runs in a row@endif.
@endif

@component('mail::button', ['url' => $monitorsUrl])
View monitors
@endcomponent

Alerts fire only when a monitor changes state, not on every run. You can turn
them off for this monitor in Spi.

Thanks,<br>
The Spi Team
@endcomponent
