@component('mail::message')
@if($recovered)
# {{ $endpointName }} is reporting in again

**{{ $endpointName }}** received a request after being overdue. Delivery has
resumed.
@else
# {{ $endpointName }} has gone silent

**{{ $endpointName }}** expects a request at least every {{ $intervalMinutes }}
minutes, but none has arrived since {{ $lastReceived }}.

Silence usually means the sender stopped running — a dead cron, a stuck queue,
or a revoked callback.
@endif

@component('mail::button', ['url' => $webhooksUrl])
View webhooks
@endcomponent

This dead-man's-switch alert fires only when an endpoint changes state, not on
every check.

Thanks,<br>
The Spi Team
@endcomponent
