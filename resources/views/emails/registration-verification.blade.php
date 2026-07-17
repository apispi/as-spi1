@component('mail::message')
# Welcome to Spi

Thanks for signing up. Click the button below to verify your email and set up
your name and password.

@component('mail::button', ['url' => $url])
Set up my account
@endcomponent

This link will expire in 60 minutes. If you didn't request this, you can safely
ignore this email.

Thanks,<br>
The Spi Team
@endcomponent
