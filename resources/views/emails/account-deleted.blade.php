@component('mail::message')
# Your account has been deleted

Hi {{ $firstName }},

Your Spi account and all of its data — saved requests, collections,
environments, monitors, reports, API keys and sessions — have been permanently
deleted, as you requested. This can't be undone, and nothing was kept.

If you didn't request this, contact us immediately at {{ $supportEmail }}.

You're welcome back any time.

@component('mail::button', ['url' => $signupUrl])
Create a new account
@endcomponent

Thanks for trying Spi,<br>
The Spi Team
@endcomponent
