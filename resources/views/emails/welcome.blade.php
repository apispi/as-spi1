@component('mail::message')
# Welcome to Spi, {{ $firstName }}

Your workspace is ready. Spi lets you send requests over REST, MCP, A2A, gRPC,
MQTT and AMQP from one place, then turn the ones that matter into monitored,
asserted suites.

A good first three minutes:

1. **Send a request** in the Tester — any protocol, any endpoint.
2. **Save it**, and add assertions so a green 200 with the wrong body still fails.
3. **Put it on a monitor** to run on a schedule, with alerts when it breaks.

@component('mail::button', ['url' => $testerUrl])
Open the Tester
@endcomponent

New to Spi? The [quickstart guide]({{ $docsUrl }}) walks through your first
request, environments and variables, and collections.

Thanks,<br>
The Spi Team
@endcomponent
